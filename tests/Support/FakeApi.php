<?php

declare(strict_types=1);

namespace mattstein\usesend\tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A useSend API that answers with queued responses and records every request.
 *
 * It sits behind Guzzle’s default middleware, so redirects and HTTP errors
 * are handled exactly as they would be in production. A request beyond the
 * queue fails the test.
 */
final class FakeApi
{
    public readonly Client $client;

    public readonly HandlerStack $handler;

    /** @var list<array{request: RequestInterface, options: array<array-key, mixed>}> */
    private array $history = [];

    public function __construct(ResponseInterface|Throwable ...$responses)
    {
        $this->handler = HandlerStack::create(new MockHandler($responses === [] ? [self::accepted()] : array_values($responses)));

        // At the bottom of the stack, so it sees every request that is sent,
        // including any a redirect would add.
        $this->handler->unshift(Middleware::tap(function(RequestInterface $request, array $options): void {
            $this->history[] = ['request' => $request, 'options' => $options];
        }));

        $this->client = new Client(['handler' => $this->handler]);
    }

    public static function accepted(string $emailId = 'email_1'): Response
    {
        return self::json(['emailId' => $emailId]);
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', ...$headers], (string) json_encode($body));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_map(static fn(array $entry): RequestInterface => $entry['request'], $this->history);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function lastOptions(): array
    {
        return $this->history[array_key_last($this->history) ?? 0]['options'] ?? [];
    }

    /**
     * The decoded body of the single request this API received.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        $requests = $this->requests();

        expect($requests)->toHaveCount(1);

        $payload = json_decode((string) $requests[0]->getBody(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * The Idempotency-Key of every request, or null where none was sent.
     *
     * @return list<string|null>
     */
    public function idempotencyKeys(): array
    {
        return array_map(
            static fn(RequestInterface $request): ?string => $request->hasHeader('Idempotency-Key') ? $request->getHeaderLine('Idempotency-Key') : null,
            $this->requests(),
        );
    }
}
