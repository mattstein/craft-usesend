<?php

declare(strict_types=1);

namespace mattstein\usesend\models;

use craft\base\Model;

/**
 * Settings that tune how mail is sent, set in config/usesend.php.
 *
 * The API key and base URL belong to the transport adapter instead, in
 * Settings → Email, so they can differ per environment.
 */
class Settings extends Model
{
    /**
     * @var int Extra attempts after a connection failure, server error, or
     * rate limit. Off by default; every attempt shares one idempotency key.
     */
    public int $retries = 0;

    /**
     * @var int Milliseconds to wait between attempts. A rate limit waits as
     * long as useSend asks, up to a minute, but never less than this.
     */
    public int $retryDelay = 200;

    /**
     * @var bool Whether to send embedded (cid:) images as ordinary
     * attachments. useSend has no inline images, so they are left out
     * otherwise.
     */
    public bool $attachInlineImages = false;

    /**
     * @return array<array-key, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['retries', 'retryDelay'], 'integer', 'min' => 0],
            [['attachInlineImages'], 'boolean'],
        ];
    }
}
