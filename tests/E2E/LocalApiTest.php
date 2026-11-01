<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Response\MessageStatus;

/**
 * Against a real SMSTunnel test server, never production. Skipped unless SMSTUNNEL_E2E=1.
 *
 *   SMSTUNNEL_E2E=1
 *   SMSTUNNEL_BASE_URL=http://127.0.0.1:3101   a test server
 *   SMSTUNNEL_API_KEY=...                     a test key with sms:send, sms:read, sms:inbox
 *   SMSTUNNEL_DEVICE_ID=...                   optional: the test phone
 *   SMSTUNNEL_E2E_TO=+14155550123             optional: the recipient
 *   SMSTUNNEL_E2E_RECORD=/path/sent.jsonl     optional: every accepted text is appended here, to check the database after
 */
#[Group('e2e')]
final class LocalApiTest extends TestCase
{
    private Client $client;
    private string $baseUrl;
    private string $to;
    private ?string $deviceId;

    protected function setUp(): void
    {
        if (getenv('SMSTUNNEL_E2E') !== '1') {
            self::markTestSkipped('Set SMSTUNNEL_E2E=1 (and SMSTUNNEL_BASE_URL, SMSTUNNEL_API_KEY) to run against a test server.');
        }

        $this->baseUrl = (string) getenv('SMSTUNNEL_BASE_URL');
        $apiKey = (string) getenv('SMSTUNNEL_API_KEY');
        if ($this->baseUrl === '' || $apiKey === '') {
            self::fail('SMSTUNNEL_BASE_URL and SMSTUNNEL_API_KEY are needed for the end-to-end tests.');
        }
        $host = (string) parse_url($this->baseUrl, \PHP_URL_HOST);
        if ($host === 'smstunnel.io' || str_ends_with($host, '.smstunnel.io')) {
            self::fail('The end-to-end tests never run against production. Point SMSTUNNEL_BASE_URL at a test server.');
        }

        $this->client = new Client($apiKey, $this->baseUrl);
        $this->to = getenv('SMSTUNNEL_E2E_TO') ?: '+14155550123';
        $this->deviceId = getenv('SMSTUNNEL_DEVICE_ID') ?: null;
    }

    public function testTheTestPhoneIsLinkedAndOnline(): void
    {
        $devices = $this->client->getDevices();

        self::assertGreaterThan(0, $devices->total);
        self::assertGreaterThan(0, $devices->online, 'No phone is online on the test server.');
        if ($this->deviceId !== null) {
            $phone = $devices->find($this->deviceId);
            self::assertNotNull($phone, 'The test phone is not linked to this key\'s account.');
            self::assertTrue($phone->isOnline, 'The test phone is offline.');
        }
    }

    public function testUsageReadsThePlan(): void
    {
        $usage = $this->client->getUsage();

        self::assertNotSame('', $usage->plan);
        self::assertArrayHasKey('smsClean', $usage->usage);
    }

    public function testATextReachesThePhoneAndIsSent(): void
    {
        $text = 'SMSTunnel PHP SDK end-to-end test ' . bin2hex(random_bytes(4));

        $result = $this->retryOnRateLimit(fn () => $this->client->sendSms($this->to, $text, $this->deviceId));

        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $result->messageId);
        self::assertFalse($result->queued, 'The phone was offline, so the text was queued.');
        $this->record('sms', $result->messageId, $text);

        $status = $this->waitUntilSent($result->messageId);
        self::assertSame($result->messageId, $status->id);
        self::assertSame($this->to, $status->phoneNumber);
        self::assertContains($status->status, [MessageStatus::SENT, MessageStatus::DELIVERED]);
        self::assertNotNull($status->sentAt);
    }

    public function testAVerificationCodeIsSent(): void
    {
        $code = (string) random_int(100000, 999999);
        $text = 'Your SMSTunnel PHP SDK test code is ' . $code;

        $result = $this->retryOnRateLimit(fn () => $this->client->sendVerificationCode($this->to, $text, $this->deviceId));

        self::assertNotSame('', $result->messageId);
        $this->record('verification', $result->messageId, $text);

        $status = $this->waitUntilSent($result->messageId);
        self::assertSame($this->to, $status->phoneNumber);
    }

    public function testAVerificationTextWithoutACodeIsRefused(): void
    {
        try {
            $this->client->sendVerificationCode($this->to, 'There is no code in this text');
            self::fail('A verification text without a code was accepted.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringContainsString('code', $exception->getMessage());
        }
    }

    public function testReceivedTextsCanBeRead(): void
    {
        $page = $this->client->getReceivedMessages(['limit' => 5]);

        self::assertSame(5, $page->limit);
        self::assertGreaterThanOrEqual(\count($page), $page->total);
        foreach ($page as $text) {
            self::assertNotSame('', $text->sender);
            self::assertNotSame('', $text->receivedAt);
        }
    }

    public function testAWrongKeyIsAnAuthenticationError(): void
    {
        $client = new Client('sk_live_this_key_does_not_exist_0000000000', $this->baseUrl);

        try {
            $client->getDevices();
            self::fail('A wrong key was accepted.');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->getStatusCode());
            self::assertSame('Invalid or expired API key', $exception->getMessage());
        }
    }

    public function testAnUnknownMessageIsNotFound(): void
    {
        try {
            $this->client->getMessageStatus('000000000000000000000000');
            self::fail('An unknown message was found.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    private function waitUntilSent(string $messageId): MessageStatus
    {
        $deadline = microtime(true) + 30;
        do {
            $status = $this->client->getMessageStatus($messageId);
            if ($status->status !== MessageStatus::PENDING) {
                return $status;
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        return $status;
    }

    /**
     * The test server is shared, so one 429 is waited out.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function retryOnRateLimit(callable $call): mixed
    {
        try {
            return $call();
        } catch (RateLimitException $exception) {
            sleep(min(60, max(1, $exception->getRetryAfter() ?? 15)));

            return $call();
        }
    }

    private function record(string $kind, string $messageId, string $text): void
    {
        $file = getenv('SMSTUNNEL_E2E_RECORD');
        if ($file === false || $file === '') {
            return;
        }

        $line = json_encode(['kind' => $kind, 'messageId' => $messageId, 'to' => $this->to, 'message' => $text], \JSON_THROW_ON_ERROR);
        file_put_contents($file, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }
}
