<?php

declare(strict_types=1);

use mattstein\usesend\support\EmailPayloadBuilder;
use mattstein\usesend\tests\Support\FakeApi;
use mattstein\usesend\tests\Support\TestTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

const ENDPOINT = 'https://app.usesend.com/api/v1/emails';

const API_KEY = 'us_test_key';

function basicEmail(): Email
{
    return (new Email())
        ->from(new Address('events@example.com', 'Events'))
        ->to(new Address('jane@example.com', 'Jane'))
        ->subject('Your meetup')
        ->text('plain body')
        ->html('<p>rich body</p>');
}

function plainEmail(): Email
{
    return (new Email())
        ->from('events@example.com')
        ->to('jane@example.com')
        ->subject('Your meetup')
        ->html('<p>See you there.</p>');
}

/**
 * @param array<string, string> $headers
 */
function withHeaders(Email $email, array $headers): Email
{
    foreach ($headers as $name => $value) {
        $email->getHeaders()->addTextHeader($name, $value);
    }

    return $email;
}

/**
 * @return array<string, mixed>
 */
function buildPayload(Email $email, bool $attachInlineImages = false, ?Envelope $envelope = null): array
{
    return (new EmailPayloadBuilder($attachInlineImages))->build($email, $envelope);
}

function transport(
    FakeApi $api,
    ?string $apiKey = API_KEY,
    ?string $baseUrl = 'https://app.usesend.com',
    int $retries = 0,
    int $retryDelay = 0,
    bool $attachInlineImages = false,
): TestTransport {
    return new TestTransport($apiKey, $baseUrl, $retries, $retryDelay, $attachInlineImages, $api->client);
}

function deliver(TestTransport $transport, ?RawMessage $message = null, ?Envelope $envelope = null): ?SentMessage
{
    return $transport->send($message ?? plainEmail(), $envelope);
}
