<?php

declare(strict_types=1);

use mattstein\usesend\exceptions\InvalidBaseUrlException;
use mattstein\usesend\support\BaseUrl;

it('builds the send endpoint from every shape of base url', function(string $baseUrl, string $endpoint) {
    expect(BaseUrl::make($baseUrl)->emailsEndpoint())->toBe($endpoint);
})->with([
    'bare host' => ['app.usesend.com', 'https://app.usesend.com/api/v1/emails'],
    'full url' => ['https://app.usesend.com', 'https://app.usesend.com/api/v1/emails'],
    'trailing slashes and whitespace' => ['  https://app.usesend.com///  ', 'https://app.usesend.com/api/v1/emails'],
    'copied from the docs' => ['https://app.usesend.com/api', 'https://app.usesend.com/api/v1/emails'],
    'docs url with a slash' => ['https://app.usesend.com/api/', 'https://app.usesend.com/api/v1/emails'],
    'upper-case api suffix' => ['https://app.usesend.com/API', 'https://app.usesend.com/api/v1/emails'],
    'http with a port' => ['http://localhost:3000', 'http://localhost:3000/api/v1/emails'],
    'bare host with a port' => ['localhost:3000', 'https://localhost:3000/api/v1/emails'],
    'path prefix' => ['https://example.com/usesend', 'https://example.com/usesend/api/v1/emails'],
    'path prefix ending in api' => ['https://example.com/usesend/api/', 'https://example.com/usesend/api/v1/emails'],
    'upper-case scheme' => ['HTTPS://app.usesend.com', 'https://app.usesend.com/api/v1/emails'],
]);

it('documents the cloud host as the default', function() {
    expect(BaseUrl::DEFAULT_BASE_URL)->toBe('https://app.usesend.com');
});

it('keeps the port and prefix when cast to a string', function() {
    expect((string) BaseUrl::make('http://localhost:3000/usesend/'))->toBe('http://localhost:3000/usesend');
});

it('lower-cases the host but not the prefix', function() {
    expect((string) BaseUrl::make('https://App.UseSend.COM/UseSend'))->toBe('https://app.usesend.com/UseSend');
});

it('only strips a whole api segment', function() {
    expect((string) BaseUrl::make('https://example.com/rapi'))->toBe('https://example.com/rapi');
});

it('refuses an empty base url rather than guessing an instance', function(mixed $value) {
    BaseUrl::make($value);
})->with([null, '', '   ', 42])->throws(InvalidBaseUrlException::class, 'base URL is empty');

it('rejects an unsupported scheme', function() {
    BaseUrl::make('ftp://example.com');
})->throws(InvalidBaseUrlException::class, 'unsupported scheme [ftp]. Use http or https.');

it('rejects a url without a host', function(string $value) {
    BaseUrl::make($value);
})->with(['https://', 'https:///api'])->throws(InvalidBaseUrlException::class, 'is not a valid URL');
