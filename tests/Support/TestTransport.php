<?php

declare(strict_types=1);

namespace mattstein\usesend\tests\Support;

use mattstein\usesend\UseSendTransport;

/**
 * Records the waits between attempts instead of sleeping through them.
 */
final class TestTransport extends UseSendTransport
{
    /** @var list<int> */
    public array $sleeps = [];

    protected function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
