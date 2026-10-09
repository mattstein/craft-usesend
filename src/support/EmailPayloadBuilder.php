<?php

declare(strict_types=1);

namespace mattstein\usesend\support;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonException;
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
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Turns a Symfony email into a useSend "send email" request body.
 *
 * Recipients are sent as bare addresses. useSend matches its suppression list
 * against the exact string it receives, so a display name ("Jane"
 * <jane@example.com>) would slip past a suppressed jane@example.com.
 *
 * @internal
 */
final class EmailPayloadBuilder
{
    /** useSend rejects requests with more attachments than this. */
    public const MAX_ATTACHMENTS = 10;

    /**
     * Headers that become payload fields, or that useSend and the MIME
     * structure own. Lower-cased, because that is how they are compared.
     */
    private const RESERVED_HEADERS = [
        'to', 'from', 'cc', 'bcc', 'reply-to', 'subject', 'sender', 'return-path',
        'date', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding', 'content-disposition', 'content-id',
    ];

    /** useSend drops headers with these prefixes, and its options use them. */
    private const RESERVED_HEADER_PREFIXES = ['x-usesend-', 'x-unsend-', 'resent-', 'arc-', 'x-ses-'];

    public function __construct(private readonly bool $attachInlineImages = false)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Email $email, ?Envelope $envelope = null): array
    {
        $payload = [
            'to' => $this->addresses($this->toRecipients($email, $envelope)),
            'from' => $this->from($email),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'replyTo' => $this->addresses($email->getReplyTo()),
            'subject' => $this->nonEmpty($email->getSubject()),
            ...$this->content($email),
            'inReplyToId' => MessageHeaders::get($email, UseSendTransport::IN_REPLY_TO_ID_HEADER),
            'scheduledAt' => $this->scheduledAt($email),
            'headers' => $this->headers($email),
            'attachments' => $this->attachments($email),
        ];

        if ($payload['to'] === []) {
            throw MissingRecipientException::forMessage();
        }

        if ($payload['subject'] === null && !isset($payload['templateId'])) {
            throw MissingSubjectException::forMessage();
        }

        return array_filter($payload, static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The template, or the text and HTML bodies.
     *
     * useSend requires text or HTML even with a template, then replaces the
     * subject and HTML with the template's. It never replaces the text, so
     * with a template the text is only sent when there is no HTML.
     *
     * @return array<string, mixed>
     */
    private function content(Email $email): array
    {
        $text = $this->body($email->getTextBody(), $email->getTextCharset());
        $html = $this->body($email->getHtmlBody(), $email->getHtmlCharset());

        if ($text === null && $html === null) {
            throw MissingBodyException::forMessage();
        }

        $templateId = MessageHeaders::get($email, UseSendTransport::TEMPLATE_ID_HEADER);

        if ($templateId === null) {
            return ['text' => $text, 'html' => $html];
        }

        return [
            'templateId' => $templateId,
            'variables' => $this->variables($email),
            ...($html !== null ? ['html' => $html] : ['text' => $text]),
        ];
    }

    /**
     * The "to" recipients, taken from the envelope when there is one.
     *
     * The envelope is who the mail is actually delivered to, and can differ
     * from the To header. It also holds every cc and bcc, so those are taken
     * out again, unless the address is in the To header too.
     *
     * @return list<Address>
     */
    private function toRecipients(Email $email, ?Envelope $envelope): array
    {
        if ($envelope === null) {
            return array_values($email->getTo());
        }

        $to = $this->keyed($email->getTo());
        $copies = $this->keyed([...$email->getCc(), ...$email->getBcc()]);

        $recipients = [];

        foreach ($envelope->getRecipients() as $recipient) {
            $key = strtolower($recipient->getAddress());

            if (isset($to[$key]) || !isset($copies[$key])) {
                $recipients[$key] ??= $to[$key] ?? $recipient;
            }
        }

        return array_values($recipients);
    }

    /**
     * @param Address[] $addresses
     * @return array<string, Address>
     */
    private function keyed(array $addresses): array
    {
        $keyed = [];

        foreach ($addresses as $address) {
            $keyed[strtolower($address->getAddress())] = $address;
        }

        return $keyed;
    }

    /**
     * Bare, de-duplicated addresses: see the class docblock.
     *
     * @param Address[] $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        $values = [];

        foreach ($addresses as $address) {
            $values[strtolower($address->getAddress())] ??= $address->getEncodedAddress();
        }

        return array_values($values);
    }

    private function from(Email $email): string
    {
        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            throw MissingFromAddressException::forMessage();
        }

        return $from->toString();
    }

    /**
     * Repeated headers, such as one X-Tag per tag, are joined into one,
     * because useSend takes one value per name.
     *
     * @return array<string, string>
     */
    private function headers(Email $email): array
    {
        $names = [];
        $values = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (!$header instanceof HeaderInterface) {
                continue;
            }

            $key = strtolower($header->getName());
            $value = MessageHeaders::value($header);

            if ($value === null || $this->isReserved($key)) {
                continue;
            }

            $names[$key] ??= $header->getName();
            $values[$key] = isset($values[$key]) ? $values[$key] . ', ' . $value : $value;
        }

        return array_combine(array_values($names), array_values($values));
    }

    private function isReserved(string $header): bool
    {
        if (in_array($header, self::RESERVED_HEADERS, true)) {
            return true;
        }

        foreach (self::RESERVED_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($header, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{filename: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            // getContentId() would generate an ID for every part, so the
            // disposition is what says a part is inline.
            $inline = $part->getDisposition() === 'inline';

            if ($inline && !$this->attachInlineImages) {
                continue;
            }

            $attachments[] = [
                'filename' => $this->filename($part, $inline),
                'content' => base64_encode($part->getBody()),
            ];
        }

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw TooManyAttachmentsException::forCount(count($attachments), self::MAX_ATTACHMENTS);
        }

        return $attachments;
    }

    /**
     * useSend requires a filename, so a part without one is named after its
     * media type.
     */
    private function filename(DataPart $part, bool $inline): string
    {
        return $this->nonEmpty(trim((string) $part->getFilename()))
            ?? ($inline ? 'inline' : 'attachment') . '.' . ($part->getMediaSubtype() ?: 'bin');
    }

    /**
     * useSend only accepts string values, so other JSON values are converted:
     * booleans to "true" and "false", null to "", and arrays to JSON.
     *
     * @return array<string, string>
     */
    private function variables(Email $email): array
    {
        $raw = MessageHeaders::get($email, UseSendTransport::VARIABLES_HEADER);

        if ($raw === null) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidTemplateVariablesException::because($exception->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw InvalidTemplateVariablesException::because('it is a JSON ' . get_debug_type($decoded) . '.');
        }

        return array_combine(
            array_map(strval(...), array_keys($decoded)),
            array_map(fn(mixed $value): string => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_scalar($value) => (string) $value,
                default => '',
            }, $decoded),
        );
    }

    /**
     * Normalized to the ISO 8601 form useSend requires, with an offset.
     */
    private function scheduledAt(Email $email): ?string
    {
        $value = MessageHeaders::get($email, UseSendTransport::SCHEDULED_AT_HEADER);

        if ($value === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (Exception) {
            throw InvalidScheduledAtException::unparseable($value);
        }
    }

    /**
     * A body may be a string or a stream, in any charset. useSend takes JSON,
     * so a stream is read out and everything is converted to UTF-8.
     *
     * @param resource|string|null $body
     */
    private function body(mixed $body, ?string $charset): ?string
    {
        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }

            $body = stream_get_contents($body);
        }

        $body = $this->nonEmpty(is_string($body) ? $body : null);

        if ($body === null || $charset === null || strcasecmp($charset, 'utf-8') === 0) {
            return $body;
        }

        return (string) mb_convert_encoding($body, 'UTF-8', $charset);
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
