<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use mattstein\usesend\exceptions\ApiRequestFailedException;
use mattstein\usesend\exceptions\InvalidBaseUrlException;
use mattstein\usesend\exceptions\InvalidIdempotencyKeyException;
use mattstein\usesend\exceptions\MissingApiKeyException;
use mattstein\usesend\exceptions\MissingRecipientException;
use mattstein\usesend\exceptions\UnsupportedMessageException;
use mattstein\usesend\support\MessageHeaders;
use mattstein\usesend\tests\Support\FakeApi;
use mattstein\usesend\tests\Support\TestTransport;
use mattstein\usesend\UseSendTransport;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends the message and returns the exception it failed with.
 */
function failedDelivery(TestTransport $transport, ?Email $email = null): ApiRequestFailedException
{
    try {
        deliver($transport, $email);
    } catch (ApiRequestFailedException $exception) {
        return $exception;
    }

    Assert::fail('Expected the send to fail.');
}

function connectionFailure(string $message = 'Connection refused'): ConnectException
{
    return new ConnectException($message, new Request('POST', ENDPOINT));
}

/**
 * @return list<ConnectException|Response>
 */
function times(int $count, ConnectException|Response $response): array
{
    return array_fill(0, $count, $response);
}

function withIdempotencyKey(string $key): Email
{
    return withHeaders(plainEmail(), [UseSendTransport::IDEMPOTENCY_KEY_HEADER => $key]);
}

describe('sending', function() {
    it('reports its transport name', function() {
        expect((string) new UseSendTransport(API_KEY))->toBe('usesend');
    });

    it('posts the message to the useSend send endpoint', function() {
        $api = new FakeApi();

        deliver(transport($api));

        $request = $api->requests()[0];

        expect((string) $request->getUri())->toBe(ENDPOINT)
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ' . API_KEY)
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($api->payload())->toBe([
                'to' => ['jane@example.com'],
                'from' => 'events@example.com',
                'subject' => 'Your meetup',
                'html' => '<p>See you there.</p>',
            ]);
    });

    it('sends a plain text message', function() {
        $api = new FakeApi();

        deliver(transport($api), (new Email())->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('See you there.'));

        $payload = $api->payload();

        expect($payload)->toMatchArray(['text' => 'See you there.', 'subject' => 'Hi'])
            ->and($payload)->not->toHaveKey('html');
    });

    it('sends every recipient of the envelope', function() {
        $api = new FakeApi();

        $envelope = new Envelope(new Address('events@example.com'), [new Address('jane@example.com'), new Address('sam@example.com')]);

        deliver(transport($api), plainEmail(), $envelope);

        expect($api->payload()['to'])->toBe(['jane@example.com', 'sam@example.com']);
    });

    it('converts a mime message that is not an email', function() {
        $api = new FakeApi();

        $headers = (new Headers())
            ->addMailboxListHeader('From', ['events@example.com'])
            ->addMailboxListHeader('To', ['jane@example.com'])
            ->addTextHeader('Subject', 'Hi');

        deliver(transport($api), new Message($headers, new TextPart('Body')));

        expect($api->payload())->toMatchArray(['subject' => 'Hi', 'text' => 'Body']);
    });

    it('refuses a raw message', function() {
        $api = new FakeApi();

        $envelope = new Envelope(new Address('events@example.com'), [new Address('jane@example.com')]);

        expect(fn() => deliver(transport($api), new RawMessage('raw'), $envelope))
            ->toThrow(UnsupportedMessageException::class, 'but the message was a Symfony\Component\Mime\RawMessage');

        expect($api->requests())->toBe([]);
    });

    it('validates the message before sending', function() {
        $api = new FakeApi();

        expect(fn() => deliver(transport($api), plainEmail()->to()->cc('cc@example.com')))
            ->toThrow(MissingRecipientException::class);

        expect($api->requests())->toBe([]);
    });

    it('replaces invalid utf-8 rather than failing the send', function() {
        $api = new FakeApi();

        deliver(transport($api), plainEmail()->text("Caf\xE9"));

        expect($api->payload()['text'])->toBe('Caf�');
    });

    it('sends emoji and other unicode intact', function() {
        $api = new FakeApi();

        deliver(transport($api), plainEmail()->subject('Café 🎉'));

        expect($api->payload()['subject'])->toBe('Café 🎉');
    });
});

describe('configuration', function() {
    it('sends to a self-hosted instance', function(string $baseUrl, string $endpoint) {
        $api = new FakeApi();

        deliver(transport($api, baseUrl: $baseUrl));

        expect((string) $api->requests()[0]->getUri())->toBe($endpoint);
    })->with([
        ['http://localhost:3000', 'http://localhost:3000/api/v1/emails'],
        ['mail.example.com/usesend/api/', 'https://mail.example.com/usesend/api/v1/emails'],
    ]);

    it('sends to useSend Cloud by default', function() {
        $api = new FakeApi();

        (new TestTransport(API_KEY, client: $api->client))->send(plainEmail());

        expect((string) $api->requests()[0]->getUri())->toBe(ENDPOINT);
    });

    it('trims the api key', function() {
        $api = new FakeApi();

        deliver(transport($api, apiKey: "  us_padded \n"));

        expect($api->requests()[0]->getHeaderLine('Authorization'))->toBe('Bearer us_padded');
    });

    it('can be built without an api key, but refuses to send without one', function(?string $apiKey) {
        $api = new FakeApi();
        $transport = transport($api, apiKey: $apiKey);

        expect(fn() => deliver($transport))->toThrow(MissingApiKeyException::class, 'No useSend API key is set');

        expect($api->requests())->toBe([]);
    })->with([null, '', '   ']);

    it('refuses to send to a missing or invalid base url', function(?string $baseUrl) {
        $api = new FakeApi();

        expect(fn() => deliver(transport($api, baseUrl: $baseUrl)))->toThrow(InvalidBaseUrlException::class);

        expect($api->requests())->toBe([]);
    })->with([null, '', 'ftp://example.com']);

    it('applies its timeouts and refuses redirects', function() {
        $api = new FakeApi();

        deliver(transport($api));

        expect($api->lastOptions())->toMatchArray([
            'timeout' => UseSendTransport::TIMEOUT,
            'connect_timeout' => UseSendTransport::CONNECT_TIMEOUT,
            'allow_redirects' => false,
        ]);
    });
});

describe('the useSend email id', function() {
    it('becomes the message id and a header on the sent message', function() {
        $sent = deliver(transport(new FakeApi(FakeApi::accepted('email_123'))));
        $original = $sent?->getOriginalMessage();

        expect($sent?->getMessageId())->toBe('email_123')
            ->and($original instanceof Message ? MessageHeaders::get($original, UseSendTransport::EMAIL_ID_HEADER) : null)->toBe('email_123');
    });

    it('records the id on the sent copy, not the message passed in', function() {
        $email = plainEmail();

        deliver(transport(new FakeApi()), $email);

        expect($email->getHeaders()->has(UseSendTransport::EMAIL_ID_HEADER))->toBeFalse();
    });

    it('leaves the message id alone without one', function(Response $response) {
        $sent = deliver(transport(new FakeApi($response)));
        $original = $sent?->getOriginalMessage();

        expect($sent?->getMessageId())->toContain('@')
            ->and($original instanceof Email && $original->getHeaders()->has(UseSendTransport::EMAIL_ID_HEADER))->toBeFalse();
    })->with([
        'an empty body' => [new Response(200)],
        'no email id' => [FakeApi::json([])],
        'a blank email id' => [FakeApi::json(['emailId' => ''])],
        'a non-string email id' => [FakeApi::json(['emailId' => 42])],
    ]);

    it('refuses a successful response that is not json', function() {
        $api = new FakeApi(new Response(200, ['Content-Type' => 'text/html'], '<!doctype html><title>Sign in</title>'));

        $exception = failedDelivery(transport($api));

        expect($exception->status)->toBe(200)
            ->and($exception->getMessage())->toContain('not JSON, so the email may not have been sent');
    });
});

describe('failures', function() {
    it('turns a rejected email into a readable error', function() {
        $api = new FakeApi(FakeApi::error(403, 'FORBIDDEN', "API key doesn't have access to this domain"));

        $exception = failedDelivery(transport($api));

        expect($exception->status)->toBe(403)
            ->and($exception->errorCode)->toBe('FORBIDDEN')
            ->and($exception->getMessage())->toBe(
                "useSend returned HTTP 403 (Forbidden): API key doesn't have access to this domain [POST " . ENDPOINT . ']',
            );
    });

    it('never puts the api key in an error', function(ConnectException|Response $response) {
        $exception = failedDelivery(transport(new FakeApi($response)));

        expect($exception->getMessage())->not->toContain(API_KEY);
    })->with([
        'a rejection' => [FakeApi::error(401, 'UNAUTHORIZED', 'Invalid API token')],
        'a connection failure' => [connectionFailure()],
        'a page that is not the api' => [new Response(200, [], '<html>Login</html>')],
    ]);

    it('reports failed validation field by field', function() {
        $api = new FakeApi(FakeApi::json([
            'success' => false,
            'error' => ['name' => 'ZodError', 'issues' => [['path' => ['subject'], 'message' => 'Required']]],
        ], 400));

        expect(failedDelivery(transport($api))->getMessage())->toContain('HTTP 400 (Bad Request): subject: Required');
    });

    it('explains an unreachable host', function() {
        $exception = failedDelivery(transport(new FakeApi(connectionFailure())));

        expect($exception->getMessage())->toBe('Could not reach useSend at ' . ENDPOINT . ': Connection refused')
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectException::class);
    });

    it('does not follow a redirect', function(int $status) {
        $api = new FakeApi(new Response($status, ['Location' => 'https://app.usesend.com/login']));

        expect(failedDelivery(transport($api))->getMessage())->toContain('redirecting to https://app.usesend.com/login');

        expect($api->requests())->toHaveCount(1);
    })->with([301, 302, 307, 308]);
});

describe('retries', function() {
    it('sends once and never retries by default', function() {
        $api = new FakeApi(new Response(503));
        $transport = transport($api);

        expect(failedDelivery($transport)->status)->toBe(503)
            ->and($api->requests())->toHaveCount(1)
            ->and($transport->sleeps)->toBe([]);
    });

    it('makes the configured number of extra attempts', function(int $retries) {
        $api = new FakeApi(...times($retries + 1, new Response(503)));

        expect(failedDelivery(transport($api, retries: $retries))->getMessage())->toContain('HTTP 503')
            ->and($api->requests())->toHaveCount($retries + 1);
    })->with([1, 2, 3]);

    it('stops retrying once a send succeeds', function() {
        $api = new FakeApi(new Response(500), new Response(502), FakeApi::accepted());

        expect(deliver(transport($api, retries: 3))?->getMessageId())->toBe('email_1')
            ->and($api->requests())->toHaveCount(3);
    });

    it('retries a connection failure', function() {
        $api = new FakeApi(connectionFailure('Connection timed out'), FakeApi::accepted());

        expect(deliver(transport($api, retries: 1))?->getMessageId())->toBe('email_1')
            ->and($api->requests())->toHaveCount(2);
    });

    it('gives up on a host that stays unreachable', function() {
        $api = new FakeApi(...times(3, connectionFailure()));

        expect(failedDelivery(transport($api, retries: 2))->getMessage())->toContain('Could not reach useSend')
            ->and($api->requests())->toHaveCount(3);
    });

    it('never retries a rejected email', function(int $status) {
        $api = new FakeApi(FakeApi::json(['error' => ['message' => 'No']], $status));

        expect(failedDelivery(transport($api, retries: 3))->status)->toBe($status)
            ->and($api->requests())->toHaveCount(1);
    })->with([400, 401, 403, 404, 422]);

    it('waits between attempts', function() {
        $transport = transport(new FakeApi(...times(3, new Response(500))), retries: 2, retryDelay: 250);

        failedDelivery($transport);

        expect($transport->sleeps)->toBe([250, 250]);
    });

    it('waits between attempts after a connection failure', function() {
        $transport = transport(new FakeApi(connectionFailure(), FakeApi::accepted()), retries: 1, retryDelay: 250);

        deliver($transport);

        expect($transport->sleeps)->toBe([250]);
    });

    it('retries a rate limit after the time useSend asks for', function() {
        $api = new FakeApi(FakeApi::error(429, 'RATE_LIMITED', 'Rate limit exceeded.', ['Retry-After' => '2']), FakeApi::accepted());
        $transport = transport($api, retries: 1, retryDelay: 100);

        expect(deliver($transport)?->getMessageId())->toBe('email_1')
            ->and($transport->sleeps)->toBe([2000]);
    });

    it('waits at least the configured time for a rate limit', function(?string $retryAfter) {
        $api = new FakeApi(new Response(429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter]), FakeApi::accepted());
        $transport = transport($api, retries: 1, retryDelay: 300);

        deliver($transport);

        expect($transport->sleeps)->toBe([300]);
    })->with([
        'no header' => [null],
        'a shorter wait' => ['0'],
        'an http date' => ['Wed, 21 Oct 2026 07:28:00 GMT'],
    ]);

    it('caps a long rate limit wait', function() {
        $transport = transport(new FakeApi(new Response(429, ['Retry-After' => '3600']), FakeApi::accepted()), retries: 1);

        deliver($transport);

        expect($transport->sleeps)->toBe([60_000]);
    });

    it('only reads retry-after on a rate limit', function() {
        $transport = transport(new FakeApi(new Response(503, ['Retry-After' => '30']), FakeApi::accepted()), retries: 1, retryDelay: 100);

        deliver($transport);

        expect($transport->sleeps)->toBe([100]);
    });

    it('retries while a duplicate of the send is still in progress', function() {
        $api = new FakeApi(
            FakeApi::error(409, 'NOT_UNIQUE', 'Request with same Idempotency-Key is in progress. Retry later.'),
            FakeApi::accepted('email_original'),
        );

        expect(deliver(transport($api, retries: 1))?->getMessageId())->toBe('email_original')
            ->and($api->requests())->toHaveCount(2);
    });

    it('never retries a key reused for a different email', function() {
        $api = new FakeApi(FakeApi::error(409, 'NOT_UNIQUE', 'Idempotency-Key already used with a different payload'));

        expect(failedDelivery(transport($api, retries: 3), withIdempotencyKey('order-42'))->errorCode)->toBe('NOT_UNIQUE')
            ->and($api->requests())->toHaveCount(1);
    });

    it('treats a negative retry count as none', function() {
        $api = new FakeApi(new Response(503));

        failedDelivery(transport($api, retries: -1));

        expect($api->requests())->toHaveCount(1)
            ->and($api->idempotencyKeys())->toBe([null]);
    });
});

describe('idempotency', function() {
    it('sends no key when nothing asks for one', function() {
        $api = new FakeApi();

        deliver(transport($api));

        expect($api->idempotencyKeys())->toBe([null]);
    });

    it('generates one key per send when retries are on', function() {
        $api = new FakeApi(FakeApi::accepted(), FakeApi::accepted());
        $transport = transport($api, retries: 2);

        deliver($transport);
        deliver($transport);

        [$first, $second] = $api->idempotencyKeys();

        expect($first)->toBeString()->toHaveLength(32)
            ->and($second)->toBeString()->toHaveLength(32)
            ->and($first)->not->toBe($second);
    });

    it('shares the generated key across the retries of a send', function() {
        $api = new FakeApi(new Response(500), connectionFailure(), FakeApi::accepted());

        deliver(transport($api, retries: 2));

        $keys = $api->idempotencyKeys();

        expect($keys)->toHaveCount(3)
            ->and(array_unique($keys))->toHaveCount(1)
            ->and($keys[0])->not->toBeNull();
    });

    it('sends the key set on the message, with or without retries', function(int $retries) {
        $api = new FakeApi(FakeApi::accepted(), FakeApi::accepted());
        $transport = transport($api, retries: $retries);

        deliver($transport, withIdempotencyKey('order-42'));
        deliver($transport, withIdempotencyKey('order-42'));

        $body = json_decode((string) $api->requests()[0]->getBody(), true);

        expect($api->idempotencyKeys())->toBe(['order-42', 'order-42'])
            ->and(is_array($body) ? $body : [])->not->toHaveKey('headers');
    })->with([0, 2]);

    it('shares the message key across retries', function() {
        $api = new FakeApi(new Response(500), FakeApi::accepted());

        deliver(transport($api, retries: 2), withIdempotencyKey('order-42'));

        expect($api->idempotencyKeys())->toBe(['order-42', 'order-42']);
    });

    it('trims the key', function() {
        $api = new FakeApi();

        deliver(transport($api), withIdempotencyKey('  signup-7  '));

        expect($api->idempotencyKeys())->toBe(['signup-7']);
    });

    it('ignores a blank key', function() {
        $api = new FakeApi();

        deliver(transport($api), withIdempotencyKey('   '));

        expect($api->idempotencyKeys())->toBe([null]);
    });

    it('accepts a key of the longest length useSend allows, counted in characters', function() {
        $api = new FakeApi();

        deliver(transport($api), withIdempotencyKey(str_repeat('é', 256)));

        expect($api->idempotencyKeys())->toBe([str_repeat('é', 256)]);
    });

    it('rejects a longer key before sending', function() {
        $api = new FakeApi();

        expect(fn() => deliver(transport($api), withIdempotencyKey(str_repeat('k', 257))))
            ->toThrow(InvalidIdempotencyKeyException::class, 'is 257 characters long but useSend accepts at most 256');

        expect($api->requests())->toBe([]);
    });
});

describe('message options', function() {
    it('sends a template with variables', function() {
        $api = new FakeApi();

        deliver(transport($api), withHeaders(plainEmail()->subject('Subject the template replaces'), [
            UseSendTransport::TEMPLATE_ID_HEADER => 'welcome_template',
            UseSendTransport::VARIABLES_HEADER => (string) json_encode(['name' => 'Jane', 'attempts' => 3, 'vip' => true, 'nothing' => null]),
        ]));

        expect($api->payload())->toBe([
            'to' => ['jane@example.com'],
            'from' => 'events@example.com',
            'subject' => 'Subject the template replaces',
            'templateId' => 'welcome_template',
            'variables' => ['name' => 'Jane', 'attempts' => '3', 'vip' => 'true', 'nothing' => ''],
            'html' => '<p>See you there.</p>',
        ]);
    });

    it('combines every option on one message', function() {
        $api = new FakeApi();

        deliver(transport($api, retries: 2), withHeaders(plainEmail(), [
            UseSendTransport::TEMPLATE_ID_HEADER => 'welcome_template',
            UseSendTransport::VARIABLES_HEADER => '{"name":"Jane"}',
            UseSendTransport::IN_REPLY_TO_ID_HEADER => 'email_41',
            UseSendTransport::SCHEDULED_AT_HEADER => '2026-10-05T09:00:00Z',
            UseSendTransport::IDEMPOTENCY_KEY_HEADER => 'welcome-7',
        ]));

        $payload = $api->payload();

        expect($payload)->toMatchArray([
            'templateId' => 'welcome_template',
            'variables' => ['name' => 'Jane'],
            'inReplyToId' => 'email_41',
            'scheduledAt' => '2026-10-05T09:00:00+00:00',
        ])
            ->and($payload)->not->toHaveKey('headers')
            ->and($api->idempotencyKeys())->toBe(['welcome-7']);
    });

    it('forwards custom headers and attachments', function() {
        $api = new FakeApi();

        $email = withHeaders(plainEmail(), ['X-Campaign' => 'Spring “launch”', 'X-Tag' => 'welcome'])
            ->attach('the file body', 'notes.txt', 'text/plain');
        $email->getHeaders()->addTextHeader('X-Tag', 'onboarding');

        deliver(transport($api), $email);

        expect($api->payload())->toMatchArray([
            'headers' => ['X-Campaign' => 'Spring “launch”', 'X-Tag' => 'welcome, onboarding'],
            'attachments' => [['filename' => 'notes.txt', 'content' => base64_encode('the file body')]],
        ]);
    });

    it('skips inline images unless configured to attach them', function(bool $attach, int $count) {
        $api = new FakeApi();

        deliver(transport($api, attachInlineImages: $attach), plainEmail()->embed('PNGDATA', 'logo.png', 'image/png'));

        expect($api->payload()['attachments'] ?? [])->toHaveCount($count);
    })->with([[false, 0], [true, 1]]);

    it('sends copied recipients once, and keeps a recipient who is also copied', function() {
        $api = new FakeApi();

        deliver(transport($api), plainEmail()
            ->to(new Address('jane@example.com', 'Jane'), 'sam@example.com')
            ->cc('sam@example.com', 'cc@example.com')
            ->bcc('bcc@example.com'));

        expect($api->payload())->toMatchArray([
            'to' => ['jane@example.com', 'sam@example.com'],
            'cc' => ['sam@example.com', 'cc@example.com'],
            'bcc' => ['bcc@example.com'],
        ]);
    });
});

it('sends every request with the same headers', function() {
    $api = new FakeApi(new Response(500), FakeApi::accepted());

    deliver(transport($api, retries: 1));

    [$first, $second] = $api->requests();

    $headers = static fn(RequestInterface $request): array => array_diff_key($request->getHeaders(), ['Content-Length' => true]);

    expect($headers($first))->toBe($headers($second))
        ->and((string) $first->getBody())->toBe((string) $second->getBody());
});
