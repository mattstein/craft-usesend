<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when a message carries more attachments than useSend accepts.
 */
final class TooManyAttachmentsException extends UseSendException
{
    public static function forCount(int $count, int $maximum): self
    {
        return new self(sprintf(
            'The message has %d attachments but useSend accepts at most %d. Remove some attachments before sending.',
            $count,
            $maximum,
        ));
    }
}
