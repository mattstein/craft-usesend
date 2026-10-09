<?php

declare(strict_types=1);

namespace mattstein\usesend\exceptions;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Base class for every error thrown by this plugin.
 *
 * As a TransportExceptionInterface, it is handled the way Craft handles any
 * other failed delivery: its mailer logs the message and send() returns false.
 */
abstract class UseSendException extends RuntimeException implements TransportExceptionInterface
{
    private string $debug = '';

    public function getDebug(): string
    {
        return $this->debug;
    }

    public function appendDebug(string $debug): void
    {
        $this->debug .= $debug;
    }
}
