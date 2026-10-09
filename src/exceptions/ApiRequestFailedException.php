<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thrown when useSend refuses a request, or cannot be reached at all.
 *
 * The API key is never included in the message, so these exceptions are safe
 * to log.
 */
final class ApiRequestFailedException extends UseSendException
{
    /**
     * @param int|null $status the HTTP status, or null when no response arrived
     * @param string|null $errorCode useSend’s error code, such as "RATE_LIMITED"
     */
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $responseBody = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function connectionFailed(string $endpoint, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach useSend at %s: %s', $endpoint, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function fromResponse(string $endpoint, ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        [$errorCode, $detail] = self::describe($body);

        $message = sprintf('useSend returned HTTP %d (%s)', $status, $response->getReasonPhrase());

        if ($status >= 300 && $status < 400) {
            $message .= sprintf(
                ', redirecting to %s. Check that the base URL points at your useSend instance.',
                $response->getHeaderLine('Location') ?: 'another address',
            );
        } elseif ($detail !== '') {
            $message .= ': ' . $detail;
        }

        if ($status === 409) {
            $message = rtrim($message, '.') . '. An idempotency key can only be reused with an identical email, for 24 hours.';
        }

        return new self(sprintf('%s [POST %s]', $message, $endpoint), $status, $body, $errorCode);
    }

    public static function unexpectedResponse(string $endpoint, ResponseInterface $response): self
    {
        return new self(
            sprintf(
                'useSend returned HTTP %d with a response that is not JSON, so the email may not have been sent.'
                . ' Check that the base URL points at your useSend instance. [POST %s]',
                $response->getStatusCode(),
                $endpoint,
            ),
            $response->getStatusCode(),
            (string) $response->getBody(),
        );
    }

    /**
     * useSend’s error code and a readable message, from either of its error
     * shapes: {"error": {"code", "message"}}, or a failed validation’s
     * {"error": {"issues": [{"path", "message"}]}}.
     *
     * @return array{?string, string}
     */
    private static function describe(string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? $decoded) : null;

        if (is_string($error) && trim($error) !== '') {
            return [null, trim($error)];
        }

        if (!is_array($error)) {
            return [null, self::excerpt($body)];
        }

        $code = is_string($error['code'] ?? null) ? $error['code'] : null;

        if (is_array($error['issues'] ?? null) && ($issues = self::issues($error['issues'])) !== '') {
            return [$code, $issues];
        }

        if (is_string($error['message'] ?? null) && trim($error['message']) !== '') {
            return [$code, trim($error['message'])];
        }

        return [$code, self::excerpt($body)];
    }

    /**
     * @param array<array-key, mixed> $issues
     */
    private static function issues(array $issues): string
    {
        $parts = [];

        foreach ($issues as $issue) {
            if (!is_array($issue) || !is_string($issue['message'] ?? null)) {
                continue;
            }

            $path = is_array($issue['path'] ?? null)
                ? implode('.', array_filter($issue['path'], is_scalar(...)))
                : '';

            $parts[] = $path === '' ? $issue['message'] : $path . ': ' . $issue['message'];
        }

        return implode('; ', $parts);
    }

    /**
     * The start of a body that is not one of useSend’s errors, such as a
     * proxy’s HTML error page, as plain text.
     */
    private static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }
}
