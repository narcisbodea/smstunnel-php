<?php

declare(strict_types=1);

namespace SmsTunnel;

use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\PermissionException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Internal\Fields;
use SmsTunnel\Response\DeviceList;
use SmsTunnel\Response\MessageStatus;
use SmsTunnel\Response\ReceivedMessages;
use SmsTunnel\Response\SendResult;
use SmsTunnel\Response\Usage;
use SmsTunnel\Transport\CurlTransport;
use SmsTunnel\Transport\HttpResponse;
use SmsTunnel\Transport\TransportInterface;

/**
 * Sends texts from your own apps through your own Android phone, from your own number.
 *
 * ```php
 * $client = new SmsTunnel\Client('sk_live_...');
 * $result = $client->sendSms('+14155550123', 'Your order has shipped.');
 * echo $result->messageId;
 * ```
 *
 * Every call throws {@see ApiException} when SMSTunnel answers with an error (with subclasses
 * {@see AuthenticationException} for 401, {@see PermissionException} for 403, {@see RateLimitException}
 * for 429) and {@see TransportException} when SMSTunnel cannot be reached.
 */
class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://smstunnel.io';

    private const RECEIVED_FILTERS = ['limit', 'page', 'since', 'phone', 'deviceId'];

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly TransportInterface $transport;

    /**
     * @param string                  $apiKey    an API key from your SMSTunnel dashboard (`sk_live_...`)
     * @param string                  $baseUrl   change it only for a self-hosted SMSTunnel or a test server
     * @param TransportInterface|null $transport defaults to {@see CurlTransport}; pass a
     *                                           {@see Transport\Psr18Transport} to use your own HTTP client
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?TransportInterface $transport = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An SMSTunnel API key is required. Create one in your SMSTunnel dashboard.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        if (preg_match('~^https?://[^/\s?#]+(/[^\s?#]*)?$~i', $baseUrl) !== 1) {
            throw new \InvalidArgumentException(\sprintf('The SMSTunnel base URL must look like https://smstunnel.io (got "%s").', $baseUrl));
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Sends one text to one number, from your phone.
     *
     * @param string      $to                 the number in international format, e.g. `+14155550123`
     * @param string      $message            up to 1600 characters (10 joined SMS)
     * @param string|null $deviceId           send through this phone (see {@see getDevices()}); null lets SMSTunnel pick one of yours
     * @param string|null $defaultCountryCode e.g. `+40`: completes numbers given without `+` or `00`. It always takes precedence over the phone's own setting.
     *
     * @throws \InvalidArgumentException when the number or the text is empty
     * @throws ApiException
     * @throws TransportException
     */
    public function sendSms(string $to, string $message, ?string $deviceId = null, ?string $defaultCountryCode = null): SendResult
    {
        return SendResult::fromArray($this->post('/api/v1/sms/send', $this->sendBody($to, $message, $deviceId, $defaultCountryCode)));
    }

    /**
     * Sends a sign-in or verification code. The text must contain the code (e.g. `Your code is 123456`);
     * SMSTunnel refuses it otherwise (400). It counts against the separate verification-code quota.
     *
     * @param string|null $deviceId           send through this phone; null lets SMSTunnel pick one of yours
     * @param string|null $defaultCountryCode e.g. `+40`, for numbers given without `+` or `00`
     *
     * @throws \InvalidArgumentException when the number or the text is empty
     * @throws ApiException
     * @throws TransportException
     */
    public function sendVerificationCode(string $to, string $message, ?string $deviceId = null, ?string $defaultCountryCode = null): SendResult
    {
        return SendResult::fromArray($this->post('/api/v1/sms/send-2fa', $this->sendBody($to, $message, $deviceId, $defaultCountryCode)));
    }

    /**
     * Where a text is now. Needs a key with the `sms:read` permission.
     *
     * @throws \InvalidArgumentException when the id is empty
     * @throws ApiException 404 when the key's account has no such text
     * @throws TransportException
     */
    public function getMessageStatus(string $messageId): MessageStatus
    {
        if (trim($messageId) === '') {
            throw new \InvalidArgumentException('The message id is empty.');
        }

        return MessageStatus::fromArray($this->data($this->get('/api/v1/sms/status/' . rawurlencode($messageId))));
    }

    /**
     * Texts your phone received, newest first. Needs a key you allowed to read received texts (`sms:inbox`,
     * a separate opt-in per key); other keys get a {@see PermissionException}. The phone syncs only texts that
     * arrive after you turn syncing on in the app.
     *
     * @param array{limit?: int, page?: int, since?: string|\DateTimeInterface, phone?: string, deviceId?: string} $filters
     *        `limit` (default 50, at most 100), `page` (from 1), `since` (only newer texts), `phone` (only from
     *        this sender), `deviceId` (only this phone)
     *
     * @throws \InvalidArgumentException on an unknown filter
     * @throws ApiException
     * @throws TransportException
     */
    public function getReceivedMessages(array $filters = []): ReceivedMessages
    {
        $unknown = array_diff(array_keys($filters), self::RECEIVED_FILTERS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown filter "%s". Use: %s.',
                implode('", "', $unknown),
                implode(', ', self::RECEIVED_FILTERS),
            ));
        }

        $query = [];
        foreach ($filters as $name => $value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::RFC3339_EXTENDED);
            }
            $value = (string) $value;
            if ($value !== '') {
                $query[$name] = $value;
            }
        }

        $path = '/api/v1/sms/received' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

        return ReceivedMessages::fromArray($this->data($this->get($path)));
    }

    /**
     * The phones linked to the key's account, and which are online.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function getDevices(): DeviceList
    {
        return DeviceList::fromArray($this->data($this->get('/api/v1/devices')));
    }

    /**
     * Today's usage, the plan's limits and what is left.
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function getUsage(): Usage
    {
        return Usage::fromArray($this->data($this->get('/api/v1/account/usage')));
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, string>
     */
    private function sendBody(string $to, string $message, ?string $deviceId, ?string $defaultCountryCode): array
    {
        if (trim($to) === '') {
            throw new \InvalidArgumentException('The recipient number is empty.');
        }
        if (trim($message) === '') {
            throw new \InvalidArgumentException('The text is empty.');
        }

        $body = ['to' => trim($to), 'message' => $message];
        if ($deviceId !== null && trim($deviceId) !== '') {
            $body['deviceId'] = trim($deviceId);
        }
        if ($defaultCountryCode !== null && trim($defaultCountryCode) !== '') {
            $body['defaultCountryCode'] = trim($defaultCountryCode);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $json = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The text could not be encoded: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->request('POST', $path, $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?string $json): array
    {
        $headers = [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'smstunnel-php/' . self::VERSION . ' PHP/' . \PHP_VERSION,
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $json);
        $body = self::decode($response);

        if ($response->statusCode >= 300 && $response->statusCode < 400) {
            $location = $response->header('location');

            throw new ApiException(
                $location !== null
                    ? \sprintf('SMSTunnel answered with a redirect to %s. Set the base URL to that address.', $location)
                    : \sprintf('SMSTunnel answered with a redirect (%d).', $response->statusCode),
                $response->statusCode,
                null,
                $body,
                $response->headers,
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw ApiException::fromResponse($response->statusCode, $body, $response->headers);
        }

        if (!\is_array($body)) {
            throw new ApiException('SMSTunnel answered with something that is not JSON.', $response->statusCode, null, $body, $response->headers);
        }

        $body = Fields::stringKeys($body);
        if (($body['success'] ?? null) === false) {
            throw ApiException::fromResponse($response->statusCode, $body, $response->headers);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        return Fields::object($body, 'data');
    }

    /**
     * The JSON body as an array, the raw text when it is not JSON, or null when it is empty.
     */
    private static function decode(HttpResponse $response): mixed
    {
        if (trim($response->body) === '') {
            return null;
        }

        try {
            return json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response->body;
        }
    }
}
