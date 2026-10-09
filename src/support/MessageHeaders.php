<?php

declare(strict_types=1);

namespace mattstein\usesend\support;

use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Message;

/**
 * Reads and writes the headers that carry useSend options on a message.
 *
 * @internal
 */
final class MessageHeaders
{
    public static function get(Message $message, string $name): ?string
    {
        $header = $message->getHeaders()->get($name);

        return $header === null ? null : self::value($header);
    }

    /**
     * Replaces any earlier value, so setting an option twice keeps the last.
     */
    public static function set(Message $message, string $name, ?string $value): void
    {
        $headers = $message->getHeaders();

        $headers->remove($name);

        if ($value !== null && trim($value) !== '') {
            $headers->addTextHeader($name, $value);
        }
    }

    /**
     * The header's value as written, trimmed, or null when it is blank.
     *
     * Text headers are read raw: getBodyAsString() would MIME-encode them
     * ("Café" becomes "=?utf-8?Q?Caf=C3=A9?="), and useSend takes plain
     * values and does its own encoding.
     */
    public static function value(HeaderInterface $header): ?string
    {
        $value = trim($header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString());

        return $value === '' ? null : $value;
    }
}
