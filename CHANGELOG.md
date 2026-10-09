# Release Notes for useSend

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- A useSend transport type for Craft’s mailer, sending through useSend’s HTTP
  API with the Guzzle client Craft already ships.
- API Key and Base URL settings in Settings → Email, both accepting environment
  variables. The base URL defaults to useSend Cloud and accepts a bare host, a
  trailing slash, a port, a path prefix, and a trailing `/api`.
- `config/usesend.php` settings for retries, the delay between them, and
  attaching inline images.
- Opt-in retries for connection failures, server errors, rate limits (honoring
  `Retry-After`, up to a minute), and a duplicate send still in progress. Every
  attempt of a send shares one generated `Idempotency-Key`.
- `X-UseSend-Template-Id`, `X-UseSend-Variables`, `X-UseSend-In-Reply-To-Id`,
  `X-UseSend-Scheduled-At`, and `X-UseSend-Idempotency-Key` message headers, with
  constants on `UseSendTransport`, for useSend features a Craft email has no
  place for.
- The useSend email ID is logged, and recorded as the sent message’s ID and an
  `X-UseSend-Email-Id` header.
- `ApiRequestFailedException` with the HTTP status, useSend’s error code, and
  the response body, and a readable message for each of useSend’s error shapes.
- An exception for each message useSend would reject, thrown before any request.
- Recipients are sent as bare addresses, so useSend’s suppression list matches
  them.
- Redirects are never followed, and a successful response that is not JSON
  fails the send, so a mistyped base URL cannot pass as a send.
- Error messages never include the API key, so they are safe to log.
