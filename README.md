# useSend for Craft CMS

Send Craft’s email through [useSend](https://usesend.com), the open-source
transactional email service, over its HTTP API instead of SMTP.

- Works with useSend Cloud, or a self-hosted instance on your own domain.
- Maps recipients, attachments, and custom headers onto useSend’s send API.
- Sends through a useSend template, schedules delivery, and threads replies.
- Fails loudly: every rejection becomes an exception that says what useSend said.
- Retries connection failures, server errors, and rate limits without ever double-sending.
- Sends recipients so useSend’s suppression list can match them.
- Adds no dependencies: it uses the Guzzle client Craft already ships.

## Requirements

- Craft CMS 5
- PHP 8.2+

## Installation

```bash
composer require mattstein/craft-usesend
php craft plugin/install usesend
```

Then go to **Settings → Email**, choose **useSend** as the transport type, and
fill in its settings.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| API Key | — | Required. A useSend API key with permission to send email. |
| Base URL | `https://app.usesend.com` | The root URL of your useSend instance. Keep the default for useSend Cloud. |

Use **Test** on the email settings page to send yourself a message through useSend.

### Environment variables

Both settings accept environment variables, and suggest them as you type. Keep
the API key in one, so it stays out of project config and out of Git:

```dotenv
USESEND_API_KEY=us_your_api_key
USESEND_BASE_URL=https://app.usesend.com
```

Then set **API Key** to `$USESEND_API_KEY` and **Base URL** to `$USESEND_BASE_URL`.

A setting that names a variable that is not set fails the send with a clear
error. A missing base URL never falls back to useSend Cloud, which would send a
self-hosted instance’s key to the wrong server.

### Self-hosted instances

The base URL accepts the shapes people actually paste:

| Base URL | Request sent to |
| --- | --- |
| `app.usesend.com` | `https://app.usesend.com/api/v1/emails` |
| `https://app.usesend.com/` | `https://app.usesend.com/api/v1/emails` |
| `https://app.usesend.com/api` | `https://app.usesend.com/api/v1/emails` |
| `http://localhost:3000` | `http://localhost:3000/api/v1/emails` |
| `https://example.com/usesend` | `https://example.com/usesend/api/v1/emails` |

Anything else, such as an `ftp://` URL, fails validation when you save.

### Config file

Sending behavior that rarely changes is set in `config/usesend.php` instead of
the control panel:

```php
<?php

return [
    // Extra attempts after a connection failure, server error, or rate limit.
    'retries' => 2,
    // Milliseconds between attempts.
    'retryDelay' => 200,
    // Send embedded (cid:) images as ordinary attachments.
    'attachInlineImages' => false,
];
```

| Key | Default | Notes |
| --- | --- | --- |
| `retries` | `0` | Extra attempts, not total attempts. See [Retries](#retries). |
| `retryDelay` | `200` | Milliseconds between attempts. |
| `attachInlineImages` | `false` | useSend has no inline images, so they are left out unless this is on. |

Requests go through `Craft::createGuzzleClient()`, so Craft’s `httpProxy`
setting and `config/guzzle.php` apply. Each request allows 30 seconds, and 10
to connect.

## Templates, scheduling, and more

useSend features that have no place in a Craft email are set with headers on
the message. The transport reads them, and never sends them as email headers:

| Constant on `UseSendTransport` | Header | Value |
| --- | --- | --- |
| `TEMPLATE_ID_HEADER` | `X-UseSend-Template-Id` | A useSend template ID |
| `VARIABLES_HEADER` | `X-UseSend-Variables` | A JSON object of template variables |
| `IN_REPLY_TO_ID_HEADER` | `X-UseSend-In-Reply-To-Id` | The useSend email ID this replies to |
| `SCHEDULED_AT_HEADER` | `X-UseSend-Scheduled-At` | An ISO 8601 date with a time zone |
| `IDEMPOTENCY_KEY_HEADER` | `X-UseSend-Idempotency-Key` | Up to 256 characters |

Set them from the mailer’s before-send event, in a module or plugin. This sends
Craft’s account activation email through a useSend template:

```php
use craft\mail\Mailer;
use craft\mail\Message;
use mattstein\usesend\UseSendTransport;
use yii\base\Event;
use yii\mail\MailEvent;

Event::on(Mailer::class, Mailer::EVENT_BEFORE_SEND, function(MailEvent $event) {
    $message = $event->message;

    if (!$message instanceof Message || $message->key !== 'account_activation') {
        return;
    }

    $message->setHeader(UseSendTransport::TEMPLATE_ID_HEADER, 'cm8activation');
    $message->setHeader(UseSendTransport::VARIABLES_HEADER, json_encode([
        'name' => ($message->variables['user'] ?? null)?->friendlyName,
        'link' => (string) ($message->variables['link'] ?? ''),
    ]));
});
```

The event fires for every message sent through Craft’s mailer, including those
other plugins send, so check the message before changing it. For a message you
compose yourself, set the headers directly:

```php
Craft::$app->getMailer()->compose()
    ->setTo($customer->email)
    ->setSubject('Your order')
    ->setHtmlBody($html)
    ->setHeader(UseSendTransport::SCHEDULED_AT_HEADER, $sendAt->format(DATE_ATOM))
    ->setHeader(UseSendTransport::IDEMPOTENCY_KEY_HEADER, 'order-confirmation-' . $order->id)
    ->send();
```

Setting a header again replaces its earlier value.

### Templates

useSend replaces the subject and HTML with the template’s. The message still
needs a body, because useSend rejects a request with neither text nor HTML even
when it uses a template. Its HTML is sent and replaced; its text is only sent
when there is no HTML, because useSend does not replace text. If the template
does not exist, useSend sends the message’s own subject and HTML.

Variables are sent as strings, because that is all useSend accepts: numbers are
cast, booleans become `"true"` or `"false"`, `null` becomes `""`, and arrays
become JSON.

### Scheduled sends

The request itself happens straight away: useSend accepts the email and
delivers it at the scheduled time. A time in the past is delivered immediately.
Cancel or reschedule an email with [useSend’s API](https://docs.usesend.com)
and its email ID.

### Replies

`X-UseSend-In-Reply-To-Id` threads an email as a reply to one useSend sent
earlier. To reply to mail useSend did not send, set the standard `In-Reply-To`
and `References` headers, which are forwarded as they are.

## Retries and idempotency

Retries are off by default. Turn them on in `config/usesend.php` to ride out a
dropped connection, a server error, or useSend’s rate limit, which is about two
requests a second on useSend Cloud:

```php
return [
    'retries' => 2,
];
```

That is up to three attempts in all, `retryDelay` milliseconds apart. A rate
limit waits as long as useSend’s `Retry-After` asks, up to a minute, and never
less than `retryDelay`. Any other 4xx response is never retried, because a
rejected email would only be rejected again.

Retrying never double-sends. The attempts of one send share a generated
`Idempotency-Key`, so if a response is lost and the request is retried, useSend
answers with the original email instead of sending a second one. The key is
random, so two intentional sends of the same message stay two sends.

A generated key only covers the retries of one send. When something sends the
same email again, such as a retried queue job, set your own key with
`X-UseSend-Idempotency-Key`, derived from what makes the email unique. A key on
the message always wins over a generated one. useSend remembers keys for 24
hours: the same key with the same email returns the original email ID, and the
same key with a different email fails with HTTP 409.

## Errors

When a send fails, Craft logs the error and `send()` returns `false`, as it does
for any transport. Every error is a `UseSendException`, which implements
Symfony’s `TransportExceptionInterface`:

| Exception | Cause |
| --- | --- |
| `MissingApiKeyException` | No API key, or its environment variable is not set. |
| `InvalidBaseUrlException` | The base URL is empty, malformed, or not http(s). |
| `MissingFromAddressException` | The message has no sender. |
| `MissingRecipientException` | The message has no `to` recipient. |
| `MissingSubjectException` | No subject and no template. |
| `MissingBodyException` | No text or HTML body. |
| `TooManyAttachmentsException` | More than ten attachments. |
| `InvalidIdempotencyKeyException` | An idempotency key over 256 characters. |
| `InvalidTemplateVariablesException` | Template variables that are not a JSON object. |
| `InvalidScheduledAtException` | A scheduled time that is not a date. |
| `UnsupportedMessageException` | A raw message, rather than a MIME message. |
| `ApiRequestFailedException` | useSend rejected the request, was unreachable, or answered with something other than its API. |

Everything except `ApiRequestFailedException` is thrown before any request is
made.

`ApiRequestFailedException` carries the HTTP `status`, useSend’s `errorCode`
(such as `RATE_LIMITED` or `NOT_UNIQUE`), and the `responseBody`. Its message
includes what useSend said, field by field for a failed validation. The API key
is never part of an error message, so they are safe to log.

Redirects are never followed, because following one would turn the request into
a GET that can answer 200 without sending anything. A redirect, or a successful
response that is not JSON, means the base URL points at something other than
useSend’s API, and fails with a message that says so.

## The useSend email ID

useSend answers each send with an email ID. Craft’s mailer does not hand the
sent message back, so the plugin logs the ID as `useSend accepted the email as
…`. It is an info message, which Craft only writes to its logs in dev mode.

Used directly, the transport returns it as the sent message’s ID, and adds it
to the sent message as an `X-UseSend-Email-Id` header:

```php
use mattstein\usesend\UseSendTransport;

$sent = (new UseSendTransport(App::env('USESEND_API_KEY')))->send($email);
$emailId = $sent?->getMessageId();
```

## How a message maps to the API

| Message | useSend field |
| --- | --- |
| Envelope recipients not only on Cc/Bcc | `to`, bare addresses |
| Cc, Bcc | `cc`, `bcc`, bare addresses |
| From (first address) | `from`, display name included |
| Reply-To | `replyTo`, bare addresses |
| Subject | `subject` |
| Text and HTML bodies | `text` and `html`, in UTF-8; with a template, HTML only when there is some |
| Attachments | `attachments`, base64 |
| Other headers | `headers` |
| `X-UseSend-Template-Id`, `X-UseSend-Variables` | `templateId`, `variables` |
| `X-UseSend-In-Reply-To-Id` | `inReplyToId` |
| `X-UseSend-Scheduled-At` | `scheduledAt` |
| `X-UseSend-Idempotency-Key` | `Idempotency-Key` request header |

Recipients are sent without display names. useSend checks its suppression list
against the exact string it receives, so `"Jane" <jane@example.com>` would get
past a suppressed `jane@example.com`. The sender keeps its display name. The
`to` recipients come from the envelope, so Craft’s `testToEmailAddress` setting
works as usual. A message needs at least one `to` recipient, because useSend
requires one.

Custom headers such as `List-Unsubscribe` are forwarded with their values as
written, so non-ASCII text arrives intact. useSend takes one value per header,
so a repeated header is joined: two `X-Tag` headers are sent as
`X-Tag: welcome, onboarding`. Headers useSend owns or drops are left out: the
address, envelope, and MIME headers, and anything starting with `X-UseSend-`,
`X-Unsend-`, `Resent-`, `ARC-`, or `X-SES-`.

Attachments are sent base64-encoded, up to the ten useSend accepts. A part with
no filename is named after its media type (`attachment.csv`), because useSend
requires a filename. Bodies in other charsets are converted to UTF-8.

The transport sends one email per message. useSend’s other endpoints, such as
batch sends, cancelling or rescheduling, and contacts, are outside what a mail
transport does.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
