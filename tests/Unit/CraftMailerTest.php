<?php

declare(strict_types=1);

use craft\mail\Mailer as CraftMailer;
use craft\mail\Message;
use GuzzleHttp\Psr7\Response;
use mattstein\usesend\exceptions\ApiRequestFailedException;
use mattstein\usesend\tests\Support\FakeApi;
use mattstein\usesend\UseSendTransport;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Markup;
use yii\base\Event;
use yii\log\Logger;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\symfonymailer\Mailer;

/**
 * Craft’s mailer is Yii’s Symfony mailer plus system messages and settings
 * that need a booted Craft. This is the part that hands messages to the
 * transport, with the before-send event the README describes.
 */
function yiiMailer(FakeApi $api, int $retries = 0): Mailer
{
    return new Mailer(['transport' => transport($api, retries: $retries)]);
}

function craftMessage(): Message
{
    return (new Message())
        ->setFrom(['events@example.com' => 'Events'])
        ->setTo(['jane@example.com' => 'Jane'])
        ->setSubject('Your meetup')
        ->setHtmlBody('<p>See you there.</p>')
        ->setTextBody('See you there.');
}

afterEach(function() {
    Event::offAll();
});

it('sends a craft message', function() {
    $api = new FakeApi();

    expect(yiiMailer($api)->send(craftMessage()))->toBeTrue()
        ->and($api->payload())->toBe([
            'to' => ['jane@example.com'],
            'from' => '"Events" <events@example.com>',
            'subject' => 'Your meetup',
            'text' => 'See you there.',
            'html' => '<p>See you there.</p>',
        ]);
});

it('sends attachments added through craft', function() {
    $api = new FakeApi();

    yiiMailer($api)->send(craftMessage()->attachContent('a,b', ['fileName' => 'report.csv', 'contentType' => 'text/csv']));

    expect($api->payload()['attachments'])->toBe([['filename' => 'report.csv', 'content' => base64_encode('a,b')]]);
});

it('takes every option from headers set in a before-send event', function() {
    $api = new FakeApi();

    Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event): void {
        if (!$event->message instanceof Message) {
            return;
        }

        $event->message
            ->setHeader(UseSendTransport::TEMPLATE_ID_HEADER, 'welcome_template')
            ->setHeader(UseSendTransport::VARIABLES_HEADER, (string) json_encode(['name' => 'Jane']))
            ->setHeader(UseSendTransport::IN_REPLY_TO_ID_HEADER, 'email_41')
            ->setHeader(UseSendTransport::SCHEDULED_AT_HEADER, (new DateTimeImmutable('2026-10-05 09:30', new DateTimeZone('Europe/Berlin')))->format(DATE_ATOM))
            ->setHeader(UseSendTransport::IDEMPOTENCY_KEY_HEADER, 'order-42-confirmation')
            ->setHeader('X-Campaign', 'spring');
    });

    yiiMailer($api)->send(craftMessage());

    expect($api->payload())->toMatchArray([
        'templateId' => 'welcome_template',
        'variables' => ['name' => 'Jane'],
        'inReplyToId' => 'email_41',
        'scheduledAt' => '2026-10-05T09:30:00+02:00',
        'headers' => ['X-Campaign' => 'spring'],
    ])->and($api->idempotencyKeys())->toBe(['order-42-confirmation']);
});

it('runs a before-send handler like the readme’s on craft’s mailer', function() {
    Event::on(CraftMailer::class, CraftMailer::EVENT_BEFORE_SEND, function(MailEvent $event) {
        $message = $event->message;

        if (!$message instanceof Message || $message->key !== 'account_activation') {
            return;
        }

        // As in the readme, typed for a stand-in user: a real one needs a booted Craft.
        $user = $message->variables['user'] ?? null;
        $link = $message->variables['link'] ?? null;

        $message->setHeader(UseSendTransport::TEMPLATE_ID_HEADER, 'cm8activation');
        $message->setHeader(UseSendTransport::VARIABLES_HEADER, (string) json_encode([
            'name' => $user instanceof stdClass ? $user->friendlyName : null,
            'link' => $link instanceof Markup ? (string) $link : '',
        ]));
    });

    $activation = craftMessage();
    $activation->key = 'account_activation';
    $activation->variables = [
        'user' => (object) ['friendlyName' => 'Jane'],
        'link' => new Markup('https://example.com/activate?code=abc', 'UTF-8'),
    ];

    $other = craftMessage();
    $other->key = 'forgot_password';

    // Craft’s own send() needs a booted Craft; this is the event it fires.
    $mailer = new CraftMailer();
    $api = new FakeApi(FakeApi::accepted(), FakeApi::accepted());

    foreach ([$activation, $other] as $message) {
        expect($mailer->beforeSend($message))->toBeTrue();
        transport($api)->send($message->getSymfonyEmail());
    }

    [$sent, $untouched] = array_map(
        static fn(Psr\Http\Message\RequestInterface $request): mixed => json_decode((string) $request->getBody(), true),
        $api->requests(),
    );

    expect($sent)->toMatchArray([
        'templateId' => 'cm8activation',
        'variables' => ['name' => 'Jane', 'link' => 'https://example.com/activate?code=abc'],
    ])->and(is_array($untouched) ? $untouched : [])->not->toHaveKey('templateId');
});

it('replaces an option set twice', function() {
    $api = new FakeApi();

    yiiMailer($api)->send(craftMessage()
        ->setHeader(UseSendTransport::TEMPLATE_ID_HEADER, 'first_template')
        ->setHeader(UseSendTransport::TEMPLATE_ID_HEADER, 'second_template'));

    expect($api->payload()['templateId'])->toBe('second_template');
});

it('leaves the message craft holds unchanged', function() {
    $message = craftMessage();

    yiiMailer(new FakeApi(FakeApi::accepted('email_123')))->send($message);

    expect($message->getHeader(UseSendTransport::EMAIL_ID_HEADER))->toBe([]);
});

it('logs the useSend email id', function() {
    $logger = new Logger();
    Yii::setLogger($logger);

    try {
        yiiMailer(new FakeApi(FakeApi::accepted('email_123')))->send(craftMessage());
    } finally {
        Yii::setLogger(null);
    }

    $messages = array_column($logger->messages, 0);

    expect($messages)->toContain('useSend accepted the email as email_123.');
});

it('fails with a transport exception, which craft logs', function() {
    try {
        yiiMailer(new FakeApi(new Response(500)))->send(craftMessage());
    } catch (TransportExceptionInterface $exception) {
        expect($exception)->toBeInstanceOf(ApiRequestFailedException::class)
            ->and($exception->getMessage())->toContain('HTTP 500');

        return;
    }

    Assert::fail('Expected the send to fail.');
});
