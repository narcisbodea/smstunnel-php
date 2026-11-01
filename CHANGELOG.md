# Changelog

All notable changes to `smstunnel/smstunnel-php` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-01

### Added

- `SmsTunnel\Client` with `sendSms()`, `sendVerificationCode()`, `getMessageStatus()`, `getReceivedMessages()`,
  `getDevices()` and `getUsage()`.
- Response objects: `SendResult`, `MessageStatus`, `ReceivedMessages` / `ReceivedMessage`, `DeviceList` / `Device`, `Usage`.
- Exceptions: `ApiException` with the HTTP status, SMSTunnel's error code and message, and the subclasses
  `AuthenticationException` (401), `PermissionException` (403) and `RateLimitException` (429, with `getRetryAfter()`);
  `TransportException` when SMSTunnel cannot be reached.
- A built-in curl transport, and `Psr18Transport` for any PSR-18 HTTP client.
