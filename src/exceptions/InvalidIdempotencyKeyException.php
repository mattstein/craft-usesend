<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when an idempotency key set on a message is longer than useSend accepts.
 */
final class InvalidIdempotencyKeyException extends UseSendException
{
    public static function tooLong(int $length, int $maximum): self
    {
        return new self(sprintf(
            'The idempotency key is %d characters long but useSend accepts at most %d.',
            $length,
            $maximum,
        ));
    }
}
