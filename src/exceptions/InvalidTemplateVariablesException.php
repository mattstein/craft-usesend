<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

use mattstein\usesend\UseSendTransport;

/**
 * Thrown when a message's template variables are not a JSON object.
 */
final class InvalidTemplateVariablesException extends UseSendException
{
    public static function because(string $reason): self
    {
        return new self(sprintf(
            'The %s header must be a JSON object of template variables: %s',
            UseSendTransport::VARIABLES_HEADER,
            $reason,
        ));
    }
}
