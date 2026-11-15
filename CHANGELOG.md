# Changelog

All notable changes to `smstunnel/smstunnel-php` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.2.0] - Unreleased

### Added

- **Scheduled texts.** `sendSms()` takes `sendAt`, a new last parameter (by name: `sendAt: $dateTime`): a
  `\DateTimeInterface`, sent in RFC 3339 with its own offset, or an ISO 8601 string with an offset, 1 minute to 30 days
  ahead. SMSTunnel keeps the text and hands it to your phone at that time, as if your app called `sendSms()` then with
  the same key. The call then returns a `SmsTunnel\Response\ScheduledText` (`id`, `sendAt`, `status`, `messageId` once
  it went out, `error` when it failed, `replayed`) instead of a `SendResult`. A string without an offset throws
  `\InvalidArgumentException` before anything is sent.
- `listScheduled($scope, $page, $limit)`: the texts this key scheduled, `waiting` (soonest first) or `done` (newest
  first, kept 30 days), as `ScheduledTexts` with `waiting` and `max` for the plan's cap. `getScheduled($id)` and
  `cancelScheduled($id)` (a `DELETE`).
- Their errors keep SMSTunnel's code in `getErrorCode()`: `SCHEDULE_TOO_SOON`, `SCHEDULE_TOO_FAR` and the other
  `SCHEDULE_*` codes; 403 `SCHEDULE_LIMIT_REACHED` (a `PermissionException`, with `max` in the body), 404
  `SCHEDULE_NOT_FOUND`, 409 `SCHEDULE_NOT_EDITABLE`.

### Changed

- `sendSms()`'s return type is now `SendResult|ScheduledText`. Without `sendAt` it returns a `SendResult` as before,
  and a conditional `@return` tells PHPStan and Psalm so. If you extend `Client` and override `sendSms()`, add the new
  parameter to your override.
- Transports also get `DELETE` requests (`cancelScheduled()`); both built-in ones already send them.

Scheduling needs SMSTunnel with scheduled texts; a server without them answers 400 "property sendAt should not exist"
(an `ApiException`).

## [1.1.0] - 2026-11-09

### Added

- **Safe retries: `$idempotencyKey`**, a new last parameter of `sendSms()` and `sendVerificationCode()` (by name:
  `idempotencyKey: 'order-1042-shipped'`). It goes out as the `Idempotency-Key` header. For 24 hours, a repeat of the
  same call with the same key gets the first answer back (the same `messageId`) instead of a second text; the same
  key with a different text, number, phone or country code is refused with an `ApiException` (422, `getErrorCode()`
  `IDEMPOTENCY_KEY_REUSED`). Use one new key per text and the same key on every retry of it. A key SMSTunnel can't
  take (empty, over 255 characters, not printable ASCII, spaces at the ends) throws `\InvalidArgumentException`
  before anything is sent.
- `SendResult::$replayed`: `true` when SMSTunnel answered a repeat of the key with the first answer (the
  `Idempotent-Replayed: true` header), so your app can tell that the call sent no new text. `SendResult::fromArray()`
  takes it as an optional second argument.
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
