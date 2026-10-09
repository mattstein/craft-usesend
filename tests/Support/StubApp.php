<?php

declare(strict_types=1);

namespace mattstein\usesend\tests\Support;

use craft\config\GeneralConfig;

/**
 * Stands in for Craft::$app with just what Craft::createGuzzleClient() and
 * Plugin::getInstance() read, so the adapter can be tested without booting
 * Craft.
 */
final class StubApp
{
    /** @var array<class-string, object> */
    public array $loadedModules = [];

    /**
     * @param array<string, mixed> $guzzle what config/guzzle.php would return
     */
    public function __construct(
        private readonly array $guzzle = [],
        private readonly GeneralConfig $general = new GeneralConfig(),
    ) {
    }

    public function getVersion(): string
    {
        return '5.0.0';
    }

    /**
     * Doubles as the config service.
     */
    public function getConfig(): self
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigFromFile(string $filename): array
    {
        return $filename === 'guzzle' ? $this->guzzle : [];
    }

    public function getLoadingConfigFile(): null
    {
        return null;
    }

    public function getGeneral(): GeneralConfig
    {
        return $this->general;
    }
}
