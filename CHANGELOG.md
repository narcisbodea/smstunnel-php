# Changelog

All notable changes to `smstunnel/smstunnel-php` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-09

### Added

- **Safe retries: `$idempotencyKey`**, a new last parameter of `sendSms()` and `sendVerificationCode()` (by name:
  `idempotencyKey: 'order-1042-shipped'`). It goes out as the `Idempotency-Key` header. For 24 hours, a repeat of the
  same call with the same key gets the first answer back (the same `messageId`) instead of a second text; the same
  key with a different text, number, phone or country code is refused with an `ApiException` (422, `getErrorCode()`
  `IDEMPOTENCY_KEY_REUSED`). Use one new key per text and the same key on every retry of it. A key SMSTunnel can't
  take (empty, over 255 characters, not printable ASCII, spaces at the ends) throws `\InvalidArgumentException`
  before anything is sent.
- `ApiException::getRetryAfter()`, moved up from `RateLimitException` (which still has it), so a 409 with
  `getErrorCode()` `IDEMPOTENCY_KEY_IN_PROGRESS` (the first try with the same key is still running) says how long to
  wait too.

The client still never retries on its own. Without `$idempotencyKey`, every call works as in 1.0.0. If you extend
`Client` and override `sendSms()` or `sendVerificationCode()`, add the new parameter to your override.

## [1.0.0] - 2026-11-01

### Added

- `SmsTunnel\Client` with `sendSms()`, `sendVerificationCode()`, `getMessageStatus()`, `getReceivedMessages()`,
  `getDevices()` and `getUsage()`.
- Response objects: `SendResult`, `MessageStatus`, `ReceivedMessages` / `ReceivedMessage`, `DeviceList` / `Device`, `Usage`.
- Exceptions: `ApiException` with the HTTP status, SMSTunnel's error code and message, and the subclasses
  `AuthenticationException` (401), `PermissionException` (403) and `RateLimitException` (429, with `getRetryAfter()`);
  `TransportException` when SMSTunnel cannot be reached.
- A built-in curl transport, and `Psr18Transport` for any PSR-18 HTTP client.
