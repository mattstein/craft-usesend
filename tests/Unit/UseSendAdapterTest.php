<?php

declare(strict_types=1);

use craft\behaviors\EnvAttributeParserBehavior;
use craft\config\GeneralConfig;
use craft\helpers\ArrayHelper;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\Smtp;
use craft\services\Security;
use GuzzleHttp\Psr7\Response;
use mattstein\usesend\exceptions\ApiRequestFailedException;
use mattstein\usesend\exceptions\InvalidBaseUrlException;
use mattstein\usesend\exceptions\MissingApiKeyException;
use mattstein\usesend\models\Settings;
use mattstein\usesend\Plugin;
use mattstein\usesend\tests\Support\FakeApi;
use mattstein\usesend\tests\Support\StubApp;
use mattstein\usesend\UseSendAdapter;
use mattstein\usesend\UseSendTransport;
use yii\base\Event;

/**
 * Swaps Craft::$app, which is typed as a full application, for a stand-in.
 */
function useCraftApp(?StubApp $app): void
{
    (new ReflectionProperty(Craft::class, 'app'))->setValue(null, $app);
}

/**
 * Points Craft::createGuzzleClient() at the fake API, through the handler a
 * site could set in config/guzzle.php.
 */
function craftSendingTo(FakeApi $api, GeneralConfig $general = new GeneralConfig()): StubApp
{
    $app = new StubApp(['handler' => $api->handler], $general);
    useCraftApp($app);

    return $app;
}

/**
 * The adapter, configured as Craft configures it from the project config.
 *
 * @param array<string, mixed> $settings
 */
function adapter(array $settings = []): UseSendAdapter
{
    return new UseSendAdapter($settings);
}

/**
 * The adapter’s transport, sending through the fake API.
 *
 * @param array<string, mixed> $settings
 */
function adapterTransport(FakeApi $api, array $settings = ['apiKey' => API_KEY]): UseSendTransport
{
    craftSendingTo($api);

    return adapter($settings)->defineTransport();
}

function installPlugin(StubApp $app): Plugin
{
    $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    $app->loadedModules[Plugin::class] = $plugin;

    return $plugin;
}

beforeEach(function() {
    $_SERVER['USESEND_TEST_API_KEY'] = 'us_from_env';
    $_SERVER['USESEND_TEST_BASE_URL'] = 'https://mail.example.com';
});

afterEach(function() {
    useCraftApp(null);
    Event::offAll();
    unset($_SERVER['USESEND_TEST_API_KEY'], $_SERVER['USESEND_TEST_BASE_URL']);
});

describe('the plugin', function() {
    it('adds useSend to the transport types', function() {
        (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor()->init();

        expect(MailerHelper::allMailerTransportTypes())->toContain(UseSendAdapter::class)
            ->and(MailerHelper::allMailerTransportTypes())->toContain(Smtp::class);
    });

    it('has config-file settings with conservative defaults', function() {
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $settings = $plugin->getSettings();

        expect($settings)->toBeInstanceOf(Settings::class)
            ->and($settings?->toArray())->toBe(['retries' => 0, 'retryDelay' => 200, 'attachInlineImages' => false]);
    });

    it('validates its settings', function(Settings $settings, array $invalid) {
        expect($settings->validate())->toBe($invalid === [])
            ->and(array_keys($settings->getErrors()))->toBe($invalid);
    })->with([
        'the defaults' => [fn() => new Settings(), []],
        'retries on' => [fn() => new Settings(['retries' => 3, 'retryDelay' => 0]), []],
        'negative numbers' => [fn() => new Settings(['retries' => -1, 'retryDelay' => -5]), ['retries', 'retryDelay']],
    ]);
});

describe('the adapter', function() {
    it('is called useSend', function() {
        expect(UseSendAdapter::displayName())->toBe('useSend');
    });

    it('defaults to useSend Cloud', function() {
        expect(adapter()->baseUrl)->toBe('https://app.usesend.com');
    });

    it('lets both settings be environment variables', function() {
        $behavior = adapter()->getBehavior('parser');

        expect($behavior)->toBeInstanceOf(EnvAttributeParserBehavior::class)
            ->and($behavior instanceof EnvAttributeParserBehavior ? $behavior->attributes : [])->toBe(['apiKey', 'baseUrl']);
    });

    it('is redacted from Craft’s settings reports', function() {
        $config = require dirname(__DIR__, 2) . '/vendor/craftcms/cms/src/config/app.php';
        $keywords = is_array($config) ? ArrayHelper::getValue($config, 'components.security.sensitiveKeywords') : null;

        $security = (new ReflectionClass(Security::class))->newInstanceWithoutConstructor();
        $security->sensitiveKeywords = array_values(array_filter(is_array($keywords) ? $keywords : [], is_string(...)));

        expect($security->sensitiveKeywords)->toContain('key');

        expect($security->isSensitive(adapter()->getAttributeLabel('apiKey')))->toBeTrue()
            ->and($security->isSensitive(adapter()->getAttributeLabel('baseUrl')))->toBeFalse();
    });

    it('validates its settings', function(UseSendAdapter $adapter, array $errors) {
        // The parser needs a booted Craft; parsing is covered by the transport tests below.
        $adapter->detachBehavior('parser');

        expect($adapter->validate())->toBe($errors === [])
            ->and(array_map(fn(array $messages) => $messages[0], $adapter->getErrors()))->toBe($errors);
    })->with([
        'valid' => [fn() => adapter(['apiKey' => API_KEY]), []],
        'a self-hosted instance' => [fn() => adapter(['apiKey' => API_KEY, 'baseUrl' => 'mail.example.com/api/']), []],
        'no api key' => [fn() => adapter(['apiKey' => '  ']), ['apiKey' => 'API Key cannot be blank.']],
        'no base url' => [fn() => adapter(['apiKey' => API_KEY, 'baseUrl' => '']), ['baseUrl' => 'Base URL cannot be blank.']],
        'an unusable base url' => [
            fn() => adapter(['apiKey' => API_KEY, 'baseUrl' => 'ftp://mail.example.com']),
            ['baseUrl' => 'The useSend base URL [ftp://mail.example.com] uses the unsupported scheme [ftp]. Use http or https.'],
        ],
    ]);

    it('trims its settings', function() {
        $adapter = adapter(['apiKey' => ' us_key ', 'baseUrl' => ' https://mail.example.com ']);
        $adapter->detachBehavior('parser');
        $adapter->validate();

        expect([$adapter->apiKey, $adapter->baseUrl])->toBe(['us_key', 'https://mail.example.com']);
    });
});

describe('the adapter’s transport', function() {
    it('sends with the configured key and instance', function() {
        $api = new FakeApi();

        adapterTransport($api, ['apiKey' => API_KEY, 'baseUrl' => 'https://mail.example.com/usesend'])->send(plainEmail());

        $request = $api->requests()[0];

        expect((string) $request->getUri())->toBe('https://mail.example.com/usesend/api/v1/emails')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ' . API_KEY);
    });

    it('reads both settings from environment variables', function() {
        $api = new FakeApi();

        adapterTransport($api, ['apiKey' => '$USESEND_TEST_API_KEY', 'baseUrl' => '$USESEND_TEST_BASE_URL'])->send(plainEmail());

        $request = $api->requests()[0];

        expect((string) $request->getUri())->toBe('https://mail.example.com/api/v1/emails')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer us_from_env');
    });

    it('can be defined without settings, so Craft’s mailer still loads', function() {
        useCraftApp(new StubApp());

        expect(adapter()->defineTransport())->toBeInstanceOf(UseSendTransport::class);
    });

    it('refuses to send when an environment variable is missing', function(UseSendAdapter $adapter, string $exception) {
        $api = new FakeApi();
        craftSendingTo($api);

        expect(fn() => $adapter->defineTransport()->send(plainEmail()))->toThrow($exception);

        expect($api->requests())->toBe([]);
    })->with([
        'the api key' => [fn() => adapter(['apiKey' => '$USESEND_MISSING']), MissingApiKeyException::class],
        // Falling back to useSend Cloud would send a self-hosted key to the wrong instance.
        'the base url' => [fn() => adapter(['apiKey' => API_KEY, 'baseUrl' => '$USESEND_MISSING']), InvalidBaseUrlException::class],
    ]);

    it('uses Craft’s guzzle client, with its proxy', function() {
        $general = new GeneralConfig();
        $general->httpProxy = 'http://proxy.example.com:3128';

        $api = new FakeApi();
        craftSendingTo($api, $general);

        adapter(['apiKey' => API_KEY])->defineTransport()->send(plainEmail());

        expect($api->requests())->toHaveCount(1)
            ->and($api->lastOptions()['proxy'] ?? null)->toBe('http://proxy.example.com:3128');
    });

    it('applies the plugin’s settings', function() {
        $api = new FakeApi(new Response(503), new Response(503), FakeApi::accepted());
        installPlugin(craftSendingTo($api))->setSettings(['retries' => 2, 'retryDelay' => 0, 'attachInlineImages' => true]);

        adapter(['apiKey' => API_KEY])->defineTransport()->send(plainEmail()->embed('PNGDATA', 'logo.png', 'image/png'));

        $requests = $api->requests();
        $payload = json_decode((string) $requests[2]->getBody(), true);

        expect($requests)->toHaveCount(3)
            ->and(is_array($payload) ? $payload['attachments'] : null)->toBe([
                ['filename' => 'logo.png', 'content' => base64_encode('PNGDATA')],
            ]);
    });

    it('does not retry without the plugin’s settings', function() {
        $api = new FakeApi(new Response(503));

        expect(fn() => adapterTransport($api)->send(plainEmail()))->toThrow(ApiRequestFailedException::class);

        expect($api->requests())->toHaveCount(1);
    });
});
