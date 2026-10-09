<?php

declare(strict_types=1);

namespace mattstein\usesend\support;

use mattstein\usesend\exceptions\InvalidBaseUrlException;

/**
 * Normalizes the configured useSend base URL and builds endpoints from it.
 *
 * Accepts every shape people paste into configuration: a bare host, a full
 * URL, a URL with a trailing slash, a URL with a port, a reverse-proxy path
 * prefix, and a URL copied from the docs that already ends in "/api".
 *
 * @internal
 */
final class BaseUrl
{
    public const DEFAULT_BASE_URL = 'https://app.usesend.com';

    /** Appended to the base URL to reach the send-email endpoint. */
    public const EMAILS_ENDPOINT = '/api/v1/emails';

    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $prefix,
    ) {
        //
    }

    public static function make(mixed $value): self
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw InvalidBaseUrlException::empty();
        }

        // A bare host ("app.usesend.com") gets the default scheme.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);

        if ($parts === false || !isset($parts['host']) || trim((string) $parts['host']) === '') {
            throw InvalidBaseUrlException::malformed($value);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw InvalidBaseUrlException::unsupportedScheme($scheme, $value);
        }

        $prefix = rtrim((string) ($parts['path'] ?? ''), '/');

        // Tolerate a base URL copied straight from the docs, which ends in
        // "/api" because the API paths are relative to it.
        if (str_ends_with(strtolower($prefix), '/api')) {
            $prefix = substr($prefix, 0, -4);
        }

        return new self($scheme, strtolower((string) $parts['host']), $parts['port'] ?? null, $prefix);
    }

    public function emailsEndpoint(): string
    {
        return $this . self::EMAILS_ENDPOINT;
    }

    public function __toString(): string
    {
        $url = $this->scheme . '://' . $this->host;

        if ($this->port !== null) {
            $url .= ':' . $this->port;
        }

        return $url . $this->prefix;
    }
}
