# SMSTunnel for PHP

`smstunnel/smstunnel-php`: a small PHP client for **[SMSTunnel](https://smstunnel.io)**. Send texts from your own PHP apps through **your own Android phone**, from **your own number**.

Your app hands the text to your SMSTunnel account and your phone sends it from its own SIM, so recipients see a number they know and can reply to. The phone is linked to your account when you sign in to the SMSTunnel app (or scan the QR code from your dashboard), and you can cut an app off at any time by deleting its API key in your dashboard.

Your phone needs the SMSTunnel app from Google Play:

<a href="https://play.google.com/store/apps/details?id=io.smstunnel.app"><img src="https://smstunnel.io/google-play-badge.png" alt="Get it on Google Play" height="56"></a>

Using Laravel? [`smstunnel/smstunnel-laravel`](https://packagist.org/packages/smstunnel/smstunnel-laravel) builds on this package, so `$user->notify(new OrderShipped)` sends the text from your phone.

## Requirements

- PHP 8.1 or newer
- the `curl` extension (most PHP installs have it), or any PSR-18 HTTP client (see [Your own HTTP client](#your-own-http-client))

No other dependencies.

## Installation

```bash
composer require smstunnel/smstunnel-php
```

## Get an API key

1. Install the [SMSTunnel app from Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) on your Android phone and sign in. Sign in at [smstunnel.io](https://smstunnel.io) on your computer with the same account: the phone appears under **Phones** (you can also link it with the QR code from **Phones → Add phone**).
2. In your SMSTunnel dashboard, open **Apps** → **Add an app** and create its key. The key can send texts and look up whether they went out (`sms:send` and `sms:read`).
3. Reading received texts only works with a key you have explicitly allowed to (**Can also read received texts**, the `sms:inbox` permission, a separate opt-in per key). Syncing received texts is off by default in the app; when you turn it on, only texts that arrive from then on are synced, and the phone's existing messages are never read.

Keep the key out of your code: put it in an environment variable such as `SMSTUNNEL_API_KEY`.

## Usage

```php
use SmsTunnel\Client;

$client = new Client((string) getenv('SMSTUNNEL_API_KEY')); // sk_live_...

$result = $client->sendSms('+14155550123', 'Your order #1042 has shipped.');

echo $result->messageId; // keep it to look up the status later
```

### Send a text

```php
$result = $client->sendSms(
    to: '+14155550123',            // international format
    message: 'See you at 10:30.',  // up to 1600 characters
    deviceId: null,                // a phone id from getDevices(), or null to let SMSTunnel pick one of yours
    defaultCountryCode: null,      // e.g. '+40': completes numbers given without + or 00
);

$result->messageId; // string
$result->queued;    // true when the phone was offline: it sends the text as soon as it is back
$result->branded;   // true when the text carries a short SMSTunnel signature (plans with a free quota)
$result->remaining; // today's quota left, e.g. ['clean' => 95, 'total' => 'unlimited'] (-1 means no limit)
```

One call sends one text to one number. To text several people, call `sendSms()` once for each.

### Send a sign-in or verification code

```php
$client->sendVerificationCode('+14155550123', 'Your sign-in code is 482913');
```

The text must contain the code, 4 to 10 digits (for example `482913` or `123-456`); SMSTunnel refuses it otherwise. Codes count against a separate quota, and the text can be up to 320 characters.

### Look up a status

Needs a key with `sms:read`.

```php
use SmsTunnel\Response\MessageStatus;

$status = $client->getMessageStatus($result->messageId);

$status->status;         // 'pending', 'sent', 'delivered' or 'failed'
$status->deliveryStatus; // the same, or 'unconfirmed': handed to the SIM but the phone got no confirmation
$status->sentAt;         // ISO 8601, or null
$status->errorMessage;   // why it failed, or null
$status->isFinal();      // true once it will not change any more

if ($status->status === MessageStatus::FAILED) {
    // ...
}
```

### Read received texts

Needs a key you allowed to read received texts (`sms:inbox`) and syncing turned on in the app.

```php
$page = $client->getReceivedMessages([
    'limit' => 20,                                     // at most 100
    'page' => 1,
    'since' => new DateTimeImmutable('-1 day'),        // or an ISO 8601 string
    'phone' => '+14155550123',                         // only from this sender
    'deviceId' => null,                                // only this phone
]);

foreach ($page as $text) {
    echo $text->sender, ': ', $text->message, PHP_EOL; // $text->receivedAt, $text->deviceName, ...
}

$page->total;   // all texts that match, on every page
$page->hasMore; // ask for the next page
```

### Your phones and your usage

```php
foreach ($client->getDevices() as $phone) {
    echo $phone->name, $phone->isOnline ? ' (online)' : ' (offline)', PHP_EOL; // $phone->id, $phone->lastSeenAt
}

$usage = $client->getUsage();
$usage->plan;      // e.g. 'pro'
$usage->usage;     // ['smsClean' => 15, 'smsBranded' => 0, 'twoFaClean' => 4]
$usage->remaining; // ['smsClean' => 'unlimited', 'twoFaClean' => 96]
```

Every response object also has `$raw`, the whole answer, for fields this version does not map yet.

## Errors

Every call throws a `SmsTunnel\Exception\SmsTunnelException` when it does not go through:

| Exception | When |
| --- | --- |
| `AuthenticationException` (401) | The API key is wrong, was deleted, or its account is suspended. |
| `PermissionException` (403) | The key lacks a permission (`getErrorCode()` is `PERMISSION_REQUIRED`, `getPermission()` names it, e.g. `sms:inbox`), or the plan does not include the feature. |
| `RateLimitException` (429) | Too many requests. `getRetryAfter()` gives the seconds to wait, when SMSTunnel says. |
| `ApiException` | Any other error answer: 400 (a number it cannot read, a verification text without a code), 404 (no such message), 5xx. The three above extend it. |
| `TransportException` | SMSTunnel could not be reached (DNS, TLS, timeout). |

```php
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Exception\TransportException;

try {
    $client->sendSms('+14155550123', 'Your table is ready.');
} catch (RateLimitException $e) {
    // wait $e->getRetryAfter() seconds and try again
} catch (ApiException $e) {
    error_log(sprintf('SMSTunnel %d %s: %s', $e->getStatusCode(), $e->getErrorCode() ?? '-', $e->getMessage()));
} catch (TransportException $e) {
    // network problem: try again later
}
```

`getMessage()` is SMSTunnel's own explanation, `getStatusCode()` (also `getCode()`) the HTTP status, `getBody()` the decoded answer. An empty number or text throws `\InvalidArgumentException` before anything is sent.

## Your own HTTP client

The built-in transport uses curl with a 30-second timeout and never follows redirects. Change the timeouts, or add curl options such as a proxy:

```php
use SmsTunnel\Transport\CurlTransport;

$client = new Client($apiKey, transport: new CurlTransport(timeout: 10, connectTimeout: 5, curlOptions: [
    CURLOPT_PROXY => 'http://proxy.internal:3128',
]));
```

Or send through any PSR-18 client (Guzzle, Symfony HttpClient, ...) so SMSTunnel calls share your app's HTTP setup:

```php
use SmsTunnel\Transport\Psr18Transport;

$factory = new GuzzleHttp\Psr7\HttpFactory(); // a PSR-17 request and stream factory
$client = new Client($apiKey, transport: new Psr18Transport(new GuzzleHttp\Client(), $factory, $factory));
```

In your own tests, implement `SmsTunnel\Transport\TransportInterface` (one method) to answer without a network, or mock `SmsTunnel\Client`.

`new Client($apiKey, 'https://your-own-host')` points the client at a self-hosted SMSTunnel; the default is `https://smstunnel.io`.

## Notes and limits

- **Rate limit**: sending allows 30 requests a minute.
- **Length**: up to 1600 characters (10 joined SMS); verification codes up to 320.
- **Numbers**: use the international format (`+14155550123`). For local numbers, pass `defaultCountryCode`: it always takes precedence (`+49`, `49` and `0049` all work).
- **Offline phone**: the text waits on your account (`queued` is `true`) and goes out when the phone is back online.

## Responsible use

Send only texts that people expect from you: alerts to yourself, appointment reminders, sign-in codes, order updates. Unsolicited messages from a personal SIM break most carriers' terms of service. Please make sure your usage complies with your carrier's terms and with local regulations (in the EU, that includes GDPR and ePrivacy rules on electronic communications).

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, level max
composer cs        # PSR-12 check (composer cs:fix to fix)
```

The end-to-end tests run only against a test server, never production:

```bash
SMSTUNNEL_E2E=1 SMSTUNNEL_BASE_URL=http://127.0.0.1:3101 SMSTUNNEL_API_KEY=... composer test:e2e
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, © NARBOWEB SRL. See [LICENSE](LICENSE).
