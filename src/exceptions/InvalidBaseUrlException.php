<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

/**
 * Thrown when the configured base URL cannot be turned into an API endpoint.
 */
final class InvalidBaseUrlException extends UseSendException
{
    public static function empty(): self
    {
        return new self(
            'The useSend base URL is empty. Set it in Settings → Email to your useSend root URL, for example https://app.usesend.com.'
        );
    }

    public static function malformed(string $value): self
    {
        return new self(sprintf('The useSend base URL [%s] is not a valid URL.', $value));
    }

    public static function unsupportedScheme(string $scheme, string $value): self
    {
        return new self(sprintf(
            'The useSend base URL [%s] uses the unsupported scheme [%s]. Use http or https.',
            $value,
            $scheme,
        ));
    }
}
