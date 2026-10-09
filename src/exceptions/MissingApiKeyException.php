<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when no API key is configured for useSend.
 */
final class MissingApiKeyException extends UseSendException
{
    public static function create(): self
    {
        return new self(
            'No useSend API key is set. Add one in Settings → Email, and check that any environment variable it names is set.'
        );
    }
}
