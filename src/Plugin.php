<?php

declare(strict_types=1);

namespace mattstein\usesend;

use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use mattstein\usesend\models\Settings;
use yii\base\Event;

/**
 * Adds useSend to Craft’s email transport types.
 */
class Plugin extends BasePlugin
{
    public function init(): void
    {
        parent::init();

        Event::on(
            MailerHelper::class,
            MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = UseSendAdapter::class;
            },
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
