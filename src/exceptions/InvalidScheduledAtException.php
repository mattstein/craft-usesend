<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

use mattstein\usesend\UseSendTransport;

/**
 * Thrown when a message's scheduled send time is not a date.
 */
final class InvalidScheduledAtException extends UseSendException
{
    public static function unparseable(string $value): self
    {
        return new self(sprintf(
            'The %s header [%s] is not a date. Use an ISO 8601 date with a time zone, such as 2026-01-31T09:00:00+00:00.',
            UseSendTransport::SCHEDULED_AT_HEADER,
            $value,
        ));
    }
}
