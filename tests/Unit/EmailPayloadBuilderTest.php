<?php

declare(strict_types=1);

use mattstein\usesend\exceptions\InvalidScheduledAtException;
use mattstein\usesend\exceptions\InvalidTemplateVariablesException;
use mattstein\usesend\exceptions\MissingBodyException;
use mattstein\usesend\exceptions\MissingFromAddressException;
use mattstein\usesend\exceptions\MissingRecipientException;
use mattstein\usesend\exceptions\MissingSubjectException;
use mattstein\usesend\exceptions\TooManyAttachmentsException;
use mattstein\usesend\UseSendTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function templateEmail(): Email
{
    return withHeaders(basicEmail(), [UseSendTransport::TEMPLATE_ID_HEADER => 'welcome_template']);
}

/**
 * @param resource|false $stream
 * @return resource
 */
function stream(mixed $stream, string $contents)
{
    if ($stream === false) {
        throw new RuntimeException('Could not open a stream.');
    }

    fwrite($stream, $contents);

    return $stream;
}

describe('addresses', function() {
    it('maps recipients and the sender', function() {
        $email = basicEmail()
            ->cc(new Address('cc@example.com', 'Copy'))
            ->bcc(new Address('bcc@example.com', 'Blind'))
            ->replyTo(new Address('replies@example.com', 'Replies'));

        $payload = buildPayload($email);

        expect($payload['from'])->toBe('"Events" <events@example.com>')
            ->and($payload['to'])->toBe(['jane@example.com'])
            ->and($payload['cc'])->toBe(['cc@example.com'])
            ->and($payload['bcc'])->toBe(['bcc@example.com'])
            ->and($payload['replyTo'])->toBe(['replies@example.com']);
    });

    it('sends a bare sender as it is', function() {
        expect(buildPayload(basicEmail()->from('events@example.com'))['from'])->toBe('events@example.com');
    });

    it('keeps a sender name that needs quoting or is not ascii', function() {
        $payload = buildPayload(basicEmail()->from(new Address('events@example.com', 'Zoë "Z" Events')));

        expect($payload['from'])->toBe('"Zoë \"Z\" Events" <events@example.com>');
    });

    it('uses the first sender when there are several', function() {
        expect(buildPayload(basicEmail()->from('first@example.com', 'second@example.com'))['from'])
            ->toBe('first@example.com');
    });

    it('sends each address once, whatever its case', function() {
        $email = basicEmail()
            ->addTo('jane@example.com', new Address('JANE@example.com', 'Jane again'))
            ->cc('cc@example.com', 'CC@example.com');

        $payload = buildPayload($email);

        expect($payload['to'])->toBe(['jane@example.com'])
            ->and($payload['cc'])->toBe(['cc@example.com']);
    });

    it('sends several reply-to addresses', function() {
        expect(buildPayload(basicEmail()->replyTo('a@example.com', 'b@example.com'))['replyTo'])
            ->toBe(['a@example.com', 'b@example.com']);
    });

    it('encodes an internationalized domain', function() {
        expect(buildPayload(basicEmail()->to('jane@exämple.com'))['to'])->toBe(['jane@xn--exmple-cua.com']);
    });

    it('omits recipients that were not set', function() {
        $payload = buildPayload(basicEmail());

        expect($payload)->not->toHaveKeys(['cc', 'bcc', 'replyTo']);
    });

    it('rejects a message with no to recipient, even with copies', function() {
        buildPayload((new Email())->from('events@example.com')->bcc('bcc@example.com')->subject('Hi')->text('Body'));
    })->throws(MissingRecipientException::class, 'no "to" recipient');

    it('rejects a message without a sender', function() {
        buildPayload((new Email())->to('jane@example.com')->subject('Hi')->text('Body'));
    })->throws(MissingFromAddressException::class, 'no "from" address');
});

describe('envelope', function() {
    it('takes the to recipients from the envelope, without the copies', function() {
        $email = basicEmail()->cc('cc@example.com')->bcc('bcc@example.com');

        $envelope = new Envelope(new Address('events@example.com'), [
            new Address('redirected@example.com'),
            new Address('cc@example.com'),
            new Address('bcc@example.com'),
        ]);

        $payload = buildPayload($email, envelope: $envelope);

        expect($payload['to'])->toBe(['redirected@example.com'])
            ->and($payload['cc'])->toBe(['cc@example.com'])
            ->and($payload['bcc'])->toBe(['bcc@example.com']);
    });

    it('keeps a to recipient that is also copied', function() {
        $email = basicEmail()->cc('Jane@Example.com');

        $envelope = new Envelope(new Address('events@example.com'), [
            new Address('jane@example.com'),
            new Address('Jane@Example.com'),
        ]);

        $payload = buildPayload($email, envelope: $envelope);

        expect($payload['to'])->toBe(['jane@example.com'])
            ->and($payload['cc'])->toBe(['Jane@Example.com']);
    });

    it('rejects an envelope that only holds copies', function() {
        $email = basicEmail()->to('cc@example.com')->cc('cc@example.com');
        $email->to();

        buildPayload($email, envelope: new Envelope(new Address('events@example.com'), [new Address('cc@example.com')]));
    })->throws(MissingRecipientException::class);
});

describe('content', function() {
    it('maps the subject and both bodies', function() {
        $payload = buildPayload(basicEmail());

        expect($payload['subject'])->toBe('Your meetup')
            ->and($payload['text'])->toBe('plain body')
            ->and($payload['html'])->toBe('<p>rich body</p>');
    });

    it('sends a text-only message', function() {
        $payload = buildPayload((new Email())->from('events@example.com')->to('jane@example.com')->subject('Hi')->text('Body'));

        expect($payload['text'])->toBe('Body')
            ->and($payload)->not->toHaveKey('html');
    });

    it('sends an html-only message', function() {
        $payload = buildPayload((new Email())->from('events@example.com')->to('jane@example.com')->subject('Hi')->html('<p>Body</p>'));

        expect($payload['html'])->toBe('<p>Body</p>')
            ->and($payload)->not->toHaveKey('text');
    });

    it('reads bodies from streams, from the start', function() {
        $text = stream(fopen('php://memory', 'r+'), 'streamed text');
        $html = stream(fopen('php://memory', 'r+'), '<p>streamed html</p>');

        $payload = buildPayload(basicEmail()->text($text)->html($html));

        expect($payload['text'])->toBe('streamed text')
            ->and($payload['html'])->toBe('<p>streamed html</p>');
    });

    it('converts bodies in other charsets to utf-8', function() {
        $email = basicEmail()
            ->text((string) mb_convert_encoding('Café', 'ISO-8859-1', 'UTF-8'), 'iso-8859-1')
            ->html((string) mb_convert_encoding('<p>Café</p>', 'Windows-1252', 'UTF-8'), 'windows-1252');

        $payload = buildPayload($email);

        expect($payload['text'])->toBe('Café')
            ->and($payload['html'])->toBe('<p>Café</p>')
            ->and(json_encode($payload))->not->toBeFalse();
    });

    it('rejects a message with no body', function() {
        buildPayload((new Email())->from('events@example.com')->to('jane@example.com')->subject('Hi'));
    })->throws(MissingBodyException::class, 'no text or HTML body');

    it('treats empty bodies as missing', function() {
        buildPayload(basicEmail()->text('')->html(''));
    })->throws(MissingBodyException::class);

    it('rejects a message without a subject', function() {
        buildPayload((new Email())->from('events@example.com')->to('jane@example.com')->text('Body'));
    })->throws(MissingSubjectException::class, 'no subject');
});

describe('templates', function() {
    it('sends the template with its variables as strings', function() {
        $email = withHeaders(templateEmail(), [UseSendTransport::VARIABLES_HEADER => (string) json_encode([
            'name' => 'Jane',
            'attempts' => 3,
            'ratio' => 1.5,
            'vip' => true,
            'archived' => false,
            'nothing' => null,
            'tags' => ['a', 'b'],
            'address' => ['city' => 'Zürich', 'site' => 'https://example.com/zh'],
            7 => 'numeric name',
        ])]);

        $payload = buildPayload($email);

        expect($payload['templateId'])->toBe('welcome_template')
            ->and($payload['variables'])->toBe([
                'name' => 'Jane',
                'attempts' => '3',
                'ratio' => '1.5',
                'vip' => 'true',
                'archived' => 'false',
                'nothing' => '',
                'tags' => '["a","b"]',
                'address' => '{"city":"Zürich","site":"https://example.com/zh"}',
                '7' => 'numeric name',
            ]);
    });

    it('sends the html for the template to replace, and leaves the text out', function() {
        $payload = buildPayload(templateEmail());

        expect($payload['html'])->toBe('<p>rich body</p>')
            ->and($payload)->not->toHaveKey('text');
    });

    it('keeps the subject, which useSend uses if the template is missing', function() {
        expect(buildPayload(templateEmail())['subject'])->toBe('Your meetup');
    });

    it('needs no subject', function() {
        $email = withHeaders(templateEmail(), []);
        $email->subject('');

        expect(buildPayload($email))->not->toHaveKey('subject');
    });

    it('sends the text when there is no html', function() {
        $email = withHeaders(
            (new Email())->from('events@example.com')->to('jane@example.com')->text('plain body'),
            [UseSendTransport::TEMPLATE_ID_HEADER => 'welcome_template'],
        );

        $payload = buildPayload($email);

        expect($payload['text'])->toBe('plain body')
            ->and($payload)->not->toHaveKey('html');
    });

    it('still needs a body', function() {
        buildPayload(withHeaders(
            (new Email())->from('events@example.com')->to('jane@example.com'),
            [UseSendTransport::TEMPLATE_ID_HEADER => 'welcome_template'],
        ));
    })->throws(MissingBodyException::class);

    it('omits variables that were not set, or were empty', function(?string $variables) {
        $email = templateEmail();

        if ($variables !== null) {
            withHeaders($email, [UseSendTransport::VARIABLES_HEADER => $variables]);
        }

        expect(buildPayload($email))->not->toHaveKey('variables');
    })->with([null, '{}', '[]', '  ']);

    it('rejects variables that are not a json object', function(string $variables, string $reason) {
        buildPayload(withHeaders(templateEmail(), [UseSendTransport::VARIABLES_HEADER => $variables]));
    })->with([
        'not json' => ['not json at all', 'Syntax error'],
        'a list' => ['["a", "b"]', 'it is a JSON array'],
        'a string' => ['"name"', 'it is a JSON string'],
        'a number' => ['42', 'it is a JSON int'],
    ])->throws(InvalidTemplateVariablesException::class);

    it('names the header in a variables error', function() {
        buildPayload(withHeaders(templateEmail(), [UseSendTransport::VARIABLES_HEADER => '[1]']));
    })->throws(InvalidTemplateVariablesException::class, 'The X-UseSend-Variables header must be a JSON object');

    it('ignores variables without a template', function() {
        $payload = buildPayload(withHeaders(basicEmail(), [UseSendTransport::VARIABLES_HEADER => '{"name":"Jane"}']));

        expect($payload)->not->toHaveKeys(['templateId', 'variables', 'headers']);
    });
});

describe('threading and scheduling', function() {
    it('passes the useSend email being replied to', function() {
        $email = withHeaders(basicEmail(), [UseSendTransport::IN_REPLY_TO_ID_HEADER => ' email_42 ']);

        expect(buildPayload($email)['inReplyToId'])->toBe('email_42');
    });

    it('normalizes the scheduled time to iso 8601 with an offset', function(string $value, string $expected) {
        $email = withHeaders(basicEmail(), [UseSendTransport::SCHEDULED_AT_HEADER => $value]);

        expect(buildPayload($email)['scheduledAt'])->toBe($expected);
    })->with([
        'with an offset' => ['2026-10-05T09:30:00+02:00', '2026-10-05T09:30:00+02:00'],
        'in utc' => ['2026-10-05T09:30:00Z', '2026-10-05T09:30:00+00:00'],
        'with milliseconds' => ['2026-10-05T09:30:00.123Z', '2026-10-05T09:30:00+00:00'],
        'as a date string' => ['2026-10-05 09:30:00 +01:00', '2026-10-05T09:30:00+01:00'],
    ]);

    it('rejects a scheduled time that is not a date', function() {
        buildPayload(withHeaders(basicEmail(), [UseSendTransport::SCHEDULED_AT_HEADER => 'next tuesday-ish']));
    })->throws(InvalidScheduledAtException::class, '[next tuesday-ish] is not a date');

    it('sends immediately when no time is set', function() {
        expect(buildPayload(basicEmail()))->not->toHaveKeys(['scheduledAt', 'inReplyToId']);
    });
});

describe('headers', function() {
    it('forwards custom headers', function() {
        $email = withHeaders(basicEmail(), [
            'X-Campaign' => 'welcome',
            'List-Unsubscribe' => '<mailto:stop@example.com>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        expect(buildPayload($email)['headers'])->toBe([
            'X-Campaign' => 'welcome',
            'List-Unsubscribe' => '<mailto:stop@example.com>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    });

    it('forwards threading headers for replies to mail useSend did not send', function() {
        $email = basicEmail();
        $email->getHeaders()->addIdHeader('In-Reply-To', 'original@example.com');
        $email->getHeaders()->addIdHeader('References', ['first@example.com', 'original@example.com']);

        expect(buildPayload($email)['headers'])->toBe([
            'In-Reply-To' => '<original@example.com>',
            'References' => '<first@example.com> <original@example.com>',
        ]);
    });

    it('sends header values as written, not mime-encoded', function() {
        $email = withHeaders(basicEmail(), ['X-Campaign' => 'Café “spring” launch']);

        expect(buildPayload($email)['headers'])->toBe(['X-Campaign' => 'Café “spring” launch']);
    });

    it('joins repeated headers, such as one tag each', function() {
        $email = withHeaders(basicEmail(), ['X-Tag' => 'welcome']);
        $email->getHeaders()->addTextHeader('x-tag', 'onboarding');

        expect(buildPayload($email)['headers'])->toBe(['X-Tag' => 'welcome, onboarding']);
    });

    it('drops blank headers', function() {
        expect(buildPayload(withHeaders(basicEmail(), ['X-Empty' => '  '])))->not->toHaveKey('headers');
    });

    it('strips headers that useSend or the message format owns', function(string $name, mixed $value) {
        $email = withHeaders(basicEmail(), ['X-Kept' => 'yes']);
        $email->getHeaders()->addHeader($name, $value);

        expect(buildPayload($email)['headers'])->toBe(['X-Kept' => 'yes']);
    })->with([
        ['Date', new DateTimeImmutable()],
        ['Message-ID', 'id@example.com'],
        ['Return-Path', new Address('bounces@example.com')],
        ['Sender', new Address('sender@example.com')],
        ['MIME-Version', '1.0'],
        ['Content-Type', 'text/plain'],
        ['Content-Transfer-Encoding', 'base64'],
        ['Content-Disposition', 'inline'],
        ['Content-ID', 'part@example.com'],
        ['Resent-To', 'resent@example.com'],
        ['X-UseSend-Template-Id', 'welcome_template'],
        ['X-UseSend-Idempotency-Key', 'order-1'],
        ['X-UseSend-Email-Id', 'email_1'],
        ['x-usesend-anything', 'value'],
        ['X-Unsend-Idempotency-Key', 'order-1'],
        ['ARC-Seal', 'i=1; a=rsa-sha256'],
        ['X-SES-Configuration-Set', 'marketing'],
    ]);

    it('never forwards the mapped address and subject headers', function() {
        $email = basicEmail()->cc('cc@example.com')->bcc('bcc@example.com')->replyTo('replies@example.com');

        expect(buildPayload($email))->not->toHaveKey('headers');
    });
});

describe('attachments', function() {
    it('encodes attachments as base64 with their filename', function() {
        $payload = buildPayload(basicEmail()->attach('the file body', 'notes.txt', 'text/plain'));

        expect($payload['attachments'])->toBe([
            ['filename' => 'notes.txt', 'content' => base64_encode('the file body')],
        ]);
    });

    it('encodes binary content', function() {
        $bytes = random_bytes(64);

        expect(buildPayload(basicEmail()->attach($bytes, 'random.bin'))['attachments'])
            ->toBe([['filename' => 'random.bin', 'content' => base64_encode($bytes)]]);
    });

    it('reads attachments from files', function() {
        $path = (string) tempnam(sys_get_temp_dir(), 'usesend');
        file_put_contents($path, 'from disk');

        try {
            $payload = buildPayload(basicEmail()->attachFromPath($path, 'disk.txt', 'text/plain'));
        } finally {
            unlink($path);
        }

        expect($payload['attachments'])->toBe([
            ['filename' => 'disk.txt', 'content' => base64_encode('from disk')],
        ]);
    });

    it('names attachments that have no filename after their media type', function(?string $filename, string $type, string $expected) {
        $email = basicEmail()->attach('plain bytes', $filename, $type);

        expect(buildPayload($email)['attachments'])->toBe([['filename' => $expected, 'content' => base64_encode('plain bytes')]]);
    })->with([
        'no filename' => [null, 'text/csv', 'attachment.csv'],
        'a blank filename' => ['  ', 'application/pdf', 'attachment.pdf'],
        'a padded filename' => [' report.pdf ', 'application/pdf', 'report.pdf'],
    ]);

    it('skips inline attachments by default', function() {
        $email = basicEmail()->embed('PNGDATA', 'logo.png', 'image/png');

        expect(buildPayload($email))->not->toHaveKey('attachments');
    });

    it('sends inline images when configured to', function() {
        $email = basicEmail()->embed('PNGDATA', 'logo.png', 'image/png')->attach('notes', 'notes.txt');

        expect(buildPayload($email, attachInlineImages: true)['attachments'])->toBe([
            ['filename' => 'logo.png', 'content' => base64_encode('PNGDATA')],
            ['filename' => 'notes.txt', 'content' => base64_encode('notes')],
        ]);
    });

    it('names an inline image that has no filename', function() {
        $email = basicEmail()->embed('PNGDATA', null, 'image/png');

        expect(buildPayload($email, attachInlineImages: true)['attachments'])
            ->toBe([['filename' => 'inline.png', 'content' => base64_encode('PNGDATA')]]);
    });

    it('accepts exactly ten attachments', function() {
        $email = basicEmail();

        foreach (range(1, 10) as $i) {
            $email->attach('body', "file-{$i}.txt", 'text/plain');
        }

        expect(buildPayload($email)['attachments'])->toHaveCount(10);
    });

    it('rejects more attachments than useSend accepts', function() {
        $email = basicEmail();

        foreach (range(1, 11) as $i) {
            $email->attach('body', "file-{$i}.txt", 'text/plain');
        }

        buildPayload($email);
    })->throws(TooManyAttachmentsException::class, 'has 11 attachments but useSend accepts at most 10');

    it('does not count skipped inline attachments against the limit', function() {
        $email = basicEmail()->embed('PNGDATA', 'logo.png', 'image/png');

        foreach (range(1, 10) as $i) {
            $email->attach('body', "file-{$i}.txt", 'text/plain');
        }

        expect(buildPayload($email)['attachments'])->toHaveCount(10);
    });
});

it('sends nothing useSend does not need', function() {
    expect(array_keys(buildPayload(basicEmail())))->toBe(['to', 'from', 'subject', 'text', 'html']);
});
