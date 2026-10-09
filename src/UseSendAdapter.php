<?php

declare(strict_types=1);

namespace mattstein\usesend;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\web\View;
use mattstein\usesend\exceptions\InvalidBaseUrlException;
use mattstein\usesend\models\Settings;
use mattstein\usesend\support\BaseUrl;

/**
 * Lets Craft send its email through useSend, from Settings → Email.
 */
class UseSendAdapter extends BaseTransportAdapter
{
    /**
     * @var string|null A useSend API key, or the environment variable that holds one.
     */
    public ?string $apiKey = null;

    /**
     * @var string|null The useSend instance to send through, or the environment variable that holds it.
     */
    public ?string $baseUrl = BaseUrl::DEFAULT_BASE_URL;

    public static function displayName(): string
    {
        return 'useSend';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey', 'baseUrl'],
            ],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            // "Key" makes Craft redact the value in its settings reports.
            'apiKey' => Craft::t('usesend', 'API Key'),
            'baseUrl' => Craft::t('usesend', 'Base URL'),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['apiKey', 'baseUrl'], 'trim'];
        $rules[] = [['apiKey', 'baseUrl'], 'required'];
        $rules[] = ['baseUrl', function(string $attribute): void {
            try {
                BaseUrl::make($this->$attribute);
            } catch (InvalidBaseUrlException $exception) {
                $this->addError($attribute, $exception->getMessage());
            }
        }];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('usesend/_settings.twig', [
            'adapter' => $this,
        ], View::TEMPLATE_MODE_CP);
    }

    public function defineTransport(): UseSendTransport
    {
        $settings = Plugin::getInstance()?->getSettings();
        $settings = $settings instanceof Settings ? $settings : new Settings();

        return new UseSendTransport(
            $this->parsed($this->apiKey),
            $this->parsed($this->baseUrl),
            retries: $settings->retries,
            retryDelay: $settings->retryDelay,
            attachInlineImages: $settings->attachInlineImages,
        );
    }

    private function parsed(?string $value): ?string
    {
        $parsed = App::parseEnv($value);

        return is_string($parsed) ? $parsed : null;
    }
}
