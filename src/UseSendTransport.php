<?php

declare(strict_types=1);

namespace mattstein\usesend;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use mattstein\usesend\exceptions\ApiRequestFailedException;
use mattstein\usesend\exceptions\InvalidIdempotencyKeyException;
use mattstein\usesend\exceptions\MissingApiKeyException;
use mattstein\usesend\exceptions\UnsupportedMessageException;
use mattstein\usesend\support\BaseUrl;
use mattstein\usesend\support\EmailPayloadBuilder;
use mattstein\usesend\support\MessageHeaders;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through useSend’s send-email endpoint.
 *
 * Nothing is validated until a message is sent, so a missing API key cannot
 * break the many requests that build Craft’s mailer without sending anything.
 */
class UseSendTransport extends AbstractTransport
{
    /** Set on a message to send it with a useSend template. */
    public const TEMPLATE_ID_HEADER = 'X-UseSend-Template-Id';

    /** A JSON object of template variables, used with TEMPLATE_ID_HEADER. */
    public const VARIABLES_HEADER = 'X-UseSend-Variables';

    /** The useSend email this message replies to, for threading. */
    public const IN_REPLY_TO_ID_HEADER = 'X-UseSend-In-Reply-To-Id';

    /** When useSend should deliver the message, as an ISO 8601 date. */
    public const SCHEDULED_AT_HEADER = 'X-UseSend-Scheduled-At';

    /** Set on a message to choose its idempotency key. */
    public const IDEMPOTENCY_KEY_HEADER = 'X-UseSend-Idempotency-Key';

    /** Added to the message after a send, holding the useSend email ID. */
    public const EMAIL_ID_HEADER = 'X-UseSend-Email-Id';

    /** The request header useSend uses to collapse duplicate sends. */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public const MAX_IDEMPOTENCY_KEY_LENGTH = 256;

    /** Seconds for the whole request. */
    public const TIMEOUT = 30;

    /** Seconds to establish the connection. */
    public const CONNECT_TIMEOUT = 10;

    /** Caps a rate limit’s Retry-After, so a bad value cannot stall a worker. */
    private const MAX_RETRY_AFTER_MILLISECONDS = 60_000;

    /**
     * @param int $retries extra attempts after the first, for failures that may not happen twice
     * @param int $retryDelay milliseconds to wait between attempts
     * @param bool $attachInlineImages whether to send embedded images as ordinary attachments, rather than leave them out
     * @param ClientInterface|null $client created with Craft::createGuzzleClient() for each send when omitted
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $baseUrl = BaseUrl::DEFAULT_BASE_URL,
        private readonly int $retries = 0,
        private readonly int $retryDelay = 200,
        private readonly bool $attachInlineImages = false,
        private readonly ?ClientInterface $client = null,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'usesend';
    }

    protected function doSend(SentMessage $message): void
    {
        $apiKey = trim((string) $this->apiKey);

        if ($apiKey === '') {
            throw MissingApiKeyException::create();
        }

        $endpoint = BaseUrl::make($this->baseUrl)->emailsEndpoint();
        $email = $this->emailFrom($message);
        $payload = (new EmailPayloadBuilder($this->attachInlineImages))->build($email, $message->getEnvelope());

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (($key = $this->idempotencyKey($email)) !== null) {
            $headers[self::IDEMPOTENCY_HEADER] = $key;
        }

        // Invalid UTF-8 in a body that claims to be UTF-8, such as pasted form
        // input, is replaced rather than failing the whole send.
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $response = $this->post($endpoint, $headers, $body);

        $this->recordEmailId($message, $this->emailId($endpoint, $response));
    }

    /**
     * Sleeps between attempts. A method of its own, so tests can skip the wait.
     */
    protected function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * Retries go to whatever may succeed a second time: connection failures,
     * server errors, rate limits, and a duplicate still being processed. A
     * rejected payload would only be rejected again.
     *
     * @param array<string, string> $headers
     */
    private function post(string $endpoint, array $headers, string $body): ResponseInterface
    {
        $client = $this->client ?? Craft::createGuzzleClient();

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $attempt < $this->retries;

            try {
                $response = $client->request('POST', $endpoint, [
                    'headers' => $headers,
                    'body' => $body,
                    'timeout' => self::TIMEOUT,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    // Following a redirect would turn the POST into a GET,
                    // which can answer 200 without sending anything.
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
            } catch (TransferException $exception) {
                if (!$canRetry) {
                    throw ApiRequestFailedException::connectionFailed($endpoint, $exception);
                }

                $this->sleep($this->retryDelay);
                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if (!$canRetry || !$this->isRetryable($response)) {
                throw ApiRequestFailedException::fromResponse($endpoint, $response);
            }

            $this->sleep($this->delayAfter($response));
        }
    }

    private function isRetryable(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return match (true) {
            $status >= 500, $status === 429 => true,
            $status === 409 => str_contains(strtolower((string) $response->getBody()), 'in progress'),
            default => false,
        };
    }

    private function delayAfter(ResponseInterface $response): int
    {
        $retryAfter = $response->getStatusCode() === 429 ? $response->getHeaderLine('Retry-After') : '';

        if (!is_numeric($retryAfter)) {
            return $this->retryDelay;
        }

        return min(max($this->retryDelay, (int) ceil((float) $retryAfter * 1000)), self::MAX_RETRY_AFTER_MILLISECONDS);
    }

    /**
     * A key set on the message always wins: the caller knows what makes a send
     * unique, and the same key survives a queue job being retried.
     *
     * Otherwise a key is generated whenever retries are on, so a retry after a
     * lost response is answered with the original email instead of a second
     * one. It is random rather than derived from the message, so two
     * intentional sends of the same message stay two sends.
     */
    private function idempotencyKey(Email $email): ?string
    {
        $key = MessageHeaders::get($email, self::IDEMPOTENCY_KEY_HEADER);

        if ($key === null) {
            return $this->retries > 0 ? bin2hex(random_bytes(16)) : null;
        }

        if (mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw InvalidIdempotencyKeyException::tooLong(mb_strlen($key), self::MAX_IDEMPOTENCY_KEY_LENGTH);
        }

        return $key;
    }

    /**
     * useSend answers a send with {"emailId": "..."}. Anything other than JSON
     * means the request reached something that is not the useSend API, such
     * as a web page served from a mistyped base URL.
     */
    private function emailId(string $endpoint, ResponseInterface $response): ?string
    {
        $body = trim((string) $response->getBody());

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw ApiRequestFailedException::unexpectedResponse($endpoint, $response);
        }

        $emailId = $decoded['emailId'] ?? null;

        return is_string($emailId) && $emailId !== '' ? $emailId : null;
    }

    /**
     * Craft’s mailer discards the sent message, so the log is the only place
     * the ID reaches when mail goes through Craft.
     */
    private function recordEmailId(SentMessage $message, ?string $emailId): void
    {
        if ($emailId === null) {
            return;
        }

        Craft::info(sprintf('useSend accepted the email as %s.', $emailId), __METHOD__);

        $message->setMessageId($emailId);

        $original = $message->getOriginalMessage();

        if ($original instanceof Message) {
            MessageHeaders::set($original, self::EMAIL_ID_HEADER, $emailId);
        }
    }

    private function emailFrom(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        return match (true) {
            $original instanceof Email => $original,
            $original instanceof Message => MessageConverter::toEmail($original),
            default => throw UnsupportedMessageException::forClass($original::class),
        };
    }
}
