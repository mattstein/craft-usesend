<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when the message has no sender, which useSend requires.
 */
final class MissingFromAddressException extends UseSendException
{
    public static function forMessage(): self
    {
        return new self(
            'The message has no "from" address. Set a System Email Address in Settings → Email, or set one on the message.'
        );
    }
}
