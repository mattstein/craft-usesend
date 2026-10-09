<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mattstein\usesend\exceptions\ApiRequestFailedException;
use mattstein\usesend\exceptions\UseSendException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * @param array<string, string>  $headers
 */
function failure(int $status, mixed $body = '', array $headers = []): ApiRequestFailedException
{
    $body = is_string($body) ? $body : (string) json_encode($body);

    return ApiRequestFailedException::fromResponse(ENDPOINT, new Response($status, $headers, $body));
}

it('reports a transport failure that is safe to log', function() {
    $exception = failure(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API token']]);

    expect($exception)->toBeInstanceOf(UseSendException::class)
        ->toBeInstanceOf(TransportExceptionInterface::class)
        ->and($exception->getMessage())->toBe('useSend returned HTTP 401 (Unauthorized): Invalid API token [POST ' . ENDPOINT . ']')
        ->and($exception->getCode())->toBe(401);
});

it('reads useSend errors', function() {
    $exception = failure(429, ['error' => ['code' => 'RATE_LIMITED', 'message' => 'Rate limit exceeded. Try again in 1 seconds.']]);

    expect($exception->status)->toBe(429)
        ->and($exception->errorCode)->toBe('RATE_LIMITED')
        ->and($exception->responseBody)->toContain('RATE_LIMITED')
        ->and($exception->getMessage())->toContain('HTTP 429 (Too Many Requests): Rate limit exceeded.');
});

it('reads failed validation', function() {
    $exception = failure(400, [
        'success' => false,
        'error' => [
            'name' => 'ZodError',
            'message' => '[{"code":"too_small"}]',
            'issues' => [
                ['code' => 'too_small', 'path' => ['attachments', 0, 'content'], 'message' => 'String must contain at least 1 character(s)'],
                ['code' => 'custom', 'path' => [], 'message' => 'Either text or html content must be provided.'],
            ],
        ],
    ]);

    expect($exception->getMessage())->toContain(
        'HTTP 400 (Bad Request): attachments.0.content: String must contain at least 1 character(s);'
        . ' Either text or html content must be provided.',
    )->and($exception->errorCode)->toBeNull();
});

it('reads an issue without a path', function() {
    $exception = failure(400, ['error' => ['issues' => [['message' => 'Invalid input']]]]);

    expect($exception->getMessage())->toContain('(Bad Request): Invalid input [POST');
});

it('quotes the body of an error with only a code', function() {
    $exception = failure(500, ['error' => ['code' => 'INTERNAL_SERVER_ERROR']]);

    expect($exception->errorCode)->toBe('INTERNAL_SERVER_ERROR')
        ->and($exception->getMessage())->toContain('(Internal Server Error): {"error":{"code":"INTERNAL_SERVER_ERROR"}} [POST');
});

it('falls back to the message when there are no readable issues', function() {
    $exception = failure(400, ['error' => ['issues' => [42], 'message' => 'Invalid payload']]);

    expect($exception->getMessage())->toContain(': Invalid payload [POST');
});

it('reads a plain message or error string', function(mixed $body, string $detail) {
    expect(failure(422, $body)->getMessage())->toContain('(Unprocessable Entity): ' . $detail . ' [POST');
})->with([
    'message' => [['message' => ' Invalid "from" address '], 'Invalid "from" address'],
    'error string' => [['error' => 'Domain not verified'], 'Domain not verified'],
]);

it('quotes an unfamiliar body as plain text', function(string $body, string $detail) {
    expect(failure(502, $body)->getMessage())->toBe('useSend returned HTTP 502 (Bad Gateway): ' . $detail . ' [POST ' . ENDPOINT . ']');
})->with([
    'html page' => ["<html><body>\n<h1>502 Bad Gateway</h1>\n<hr><center>nginx</center></body></html>", '502 Bad Gateway nginx'],
    'plain text' => ['upstream connect error', 'upstream connect error'],
    'unfamiliar json' => ['{"status":"down"}', '{"status":"down"}'],
]);

it('shortens a long body', function() {
    $message = failure(500, str_repeat('x', 500))->getMessage();

    expect($message)->toContain(str_repeat('x', 200) . '… [POST');
    expect($message)->not->toContain(str_repeat('x', 201));
});

it('leaves the detail out when the body is empty', function() {
    expect(failure(503)->getMessage())->toBe('useSend returned HTTP 503 (Service Unavailable) [POST ' . ENDPOINT . ']');
});

it('explains an idempotency conflict', function() {
    $exception = failure(409, ['error' => ['code' => 'NOT_UNIQUE', 'message' => 'Idempotency-Key already used with a different payload']]);

    expect($exception->errorCode)->toBe('NOT_UNIQUE')
        ->and($exception->getMessage())->toContain('Idempotency-Key already used with a different payload.')
        ->and($exception->getMessage())->toContain('can only be reused with an identical email, for 24 hours');
});

it('points at the base url when redirected', function() {
    $exception = failure(301, '', ['Location' => 'https://usesend.com/']);

    expect($exception->getMessage())->toBe(
        'useSend returned HTTP 301 (Moved Permanently), redirecting to https://usesend.com/.'
        . ' Check that the base URL points at your useSend instance. [POST ' . ENDPOINT . ']',
    );
});

it('explains a redirect without a location', function() {
    expect(failure(302)->getMessage())->toContain('redirecting to another address');
});

it('explains a response that is not the api', function() {
    $exception = ApiRequestFailedException::unexpectedResponse(ENDPOINT, new Response(200, [], '<html>Login</html>'));

    expect($exception->status)->toBe(200)
        ->and($exception->responseBody)->toBe('<html>Login</html>')
        ->and($exception->getMessage())->toContain('not JSON, so the email may not have been sent');
});

it('wraps a connection failure', function() {
    $previous = new ConnectException('cURL error 6: Could not resolve host', new Request('POST', ENDPOINT));

    $exception = ApiRequestFailedException::connectionFailed(ENDPOINT, $previous);

    expect($exception->getMessage())->toBe('Could not reach useSend at ' . ENDPOINT . ': cURL error 6: Could not resolve host')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->status)->toBeNull()
        ->and($exception->responseBody)->toBeNull()
        ->and($exception->getCode())->toBe(0);
});

it('collects symfony debug output', function() {
    $exception = failure(500);
    $exception->appendDebug('first ');
    $exception->appendDebug('second');

    expect($exception->getDebug())->toBe('first second');
});
