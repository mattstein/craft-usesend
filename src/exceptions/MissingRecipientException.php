<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when a message has no "to" recipient.
 */
final class MissingRecipientException extends UseSendException
{
    public static function forMessage(): self
    {
        return new self(
            'The message has no "to" recipient. useSend requires at least one, even when the message has cc or bcc recipients.'
        );
    }
}
