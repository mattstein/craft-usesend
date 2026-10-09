<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when a message has neither a text nor an HTML body.
 */
final class MissingBodyException extends UseSendException
{
    public static function forMessage(): self
    {
        return new self(
            'The message has no text or HTML body. useSend requires one, even when the message uses a template, which replaces the HTML.'
        );
    }
}
