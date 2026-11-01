<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Response\Device;
use SmsTunnel\Response\MessageStatus;
use SmsTunnel\Response\ReceivedMessage;
use SmsTunnel\Tests\Support\FakeTransport;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('sk_test_key', 'https://sms.example.test/', $this->transport);
    }

    public function testItNeedsAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key is required');

        new Client('  ', transport: $this->transport);
    }

    public function testItRefusesABaseUrlThatIsNotHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('sk_test_key', 'ftp://sms.example.test', $this->transport);
    }

    public function testItUsesTheProductionUrlByDefaultAndTrimsTheSlash(): void
    {
        self::assertSame('https://smstunnel.io', (new Client('k', transport: $this->transport))->getBaseUrl());
        self::assertSame('https://sms.example.test', $this->client->getBaseUrl());
    }

    public function testSendSmsPostsTheTextAndReadsTheResult(): void
    {
        $this->transport->respond(200, [
            'success' => true,
            'messageId' => '6ae660e96609f21a1184a301',
            'data' => [
                'messageId' => '6ae660e96609f21a1184a301',
                'branded' => false,
                'remaining' => ['clean' => -1, 'total' => 'unlimited'],
            ],
        ]);

        $result = $this->client->sendSms('+14155550123', 'Your order has shipped. Ünïcode ✓');

        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/send', $request['url']);
        self::assertSame('sk_test_key', $request['headers']['X-API-Key']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame('application/json', $request['headers']['Accept']);
        self::assertStringStartsWith('smstunnel-php/' . Client::VERSION, $request['headers']['User-Agent']);
        self::assertSame(['to' => '+14155550123', 'message' => 'Your order has shipped. Ünïcode ✓'], $this->transport->lastJson());
        self::assertStringContainsString('Ünïcode ✓', (string) $request['body'], 'unicode is sent as is');

        self::assertSame('6ae660e96609f21a1184a301', $result->messageId);
        self::assertFalse($result->queued);
        self::assertFalse($result->branded);
        self::assertSame(['clean' => -1, 'total' => 'unlimited'], $result->remaining);
        self::assertTrue($result->raw['success']);
    }

    public function testSendSmsPassesThePhoneAndTheCountryCode(): void
    {
        $this->transport->respond(200, ['success' => true, 'queued' => true, 'data' => ['messageId' => 'm1', 'branded' => true, 'queued' => true]]);

        $result = $this->client->sendSms('0721123456', 'Hi', 'device-1', '+40');

        self::assertSame(
            ['to' => '0721123456', 'message' => 'Hi', 'deviceId' => 'device-1', 'defaultCountryCode' => '+40'],
            $this->transport->lastJson(),
        );
        self::assertSame('m1', $result->messageId, 'falls back to data.messageId');
        self::assertTrue($result->queued);
        self::assertTrue($result->branded);
    }

    public function testSendSmsRefusesAnEmptyNumberOrTextWithoutCallingSmsTunnel(): void
    {
        try {
            $this->client->sendSms(' ', 'Hi');
            self::fail('An empty number was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('number', $exception->getMessage());
        }

        try {
            $this->client->sendSms('+14155550123', '');
            self::fail('An empty text was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('text', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testSendSmsRefusesTextThatIsNotUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be encoded');

        $this->client->sendSms('+14155550123', "\xB1\x31");
    }

    public function testSendVerificationCodeUsesTheVerificationEndpoint(): void
    {
        $this->transport->respond(200, ['success' => true, 'messageId' => 'otp-1', 'data' => ['messageId' => 'otp-1', 'branded' => false]]);

        $result = $this->client->sendVerificationCode('+14155550123', 'Your code is 123456', 'device-1');

        self::assertSame('https://sms.example.test/api/v1/sms/send-2fa', $this->transport->lastRequest()['url']);
        self::assertSame(['to' => '+14155550123', 'message' => 'Your code is 123456', 'deviceId' => 'device-1'], $this->transport->lastJson());
        self::assertSame('otp-1', $result->messageId);
    }

    public function testGetMessageStatusReadsTheState(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'id' => 'abc/1',
            'phoneNumber' => '+14155550123',
            'status' => 'sent',
            'deliveryStatus' => 'sent',
            'sentAt' => '2026-11-01T10:00:00.000Z',
            'errorMessage' => null,
        ]]);

        $status = $this->client->getMessageStatus('abc/1');

        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/status/abc%2F1', $request['url']);
        self::assertNull($request['body']);
        self::assertArrayNotHasKey('Content-Type', $request['headers']);

        self::assertSame('abc/1', $status->id);
        self::assertSame('+14155550123', $status->phoneNumber);
        self::assertSame(MessageStatus::SENT, $status->status);
        self::assertSame('sent', $status->deliveryStatus);
        self::assertSame('2026-11-01T10:00:00.000Z', $status->sentAt);
        self::assertNull($status->deliveredAt);
        self::assertNull($status->errorMessage);
        self::assertFalse($status->unconfirmed);
        self::assertFalse($status->isFinal());
    }

    public function testAnUnconfirmedTextIsFinal(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'id' => 'm2', 'phoneNumber' => '+14155550123', 'status' => 'failed', 'unconfirmed' => true,
            'deliveryStatus' => 'unconfirmed', 'errorMessage' => 'Not confirmed by the phone',
        ]]);

        $status = $this->client->getMessageStatus('m2');

        self::assertTrue($status->unconfirmed);
        self::assertSame(MessageStatus::UNCONFIRMED, $status->deliveryStatus);
        self::assertTrue($status->isFinal());
        self::assertSame('Not confirmed by the phone', $status->errorMessage);
    }

    public function testGetMessageStatusNeedsAnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client->getMessageStatus('');
    }

    public function testGetReceivedMessagesSendsTheFiltersAndReadsThePage(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'messages' => [
                [
                    'id' => 'r1', 'sender' => '+40712345678', 'message' => 'Thanks!', 'isEncrypted' => false,
                    'receivedAt' => '2026-10-31T21:34:05.638Z', 'deviceId' => 'device-1', 'deviceName' => 'Store phone', 'simSlot' => 1,
                ],
                ['id' => 'r2', 'sender' => '+40712345679', 'message' => null, 'isEncrypted' => true, 'encryptedPayload' => 'b64', 'receivedAt' => '2026-10-31T21:30:00.000Z'],
            ],
            'total' => 12, 'page' => 2, 'limit' => 2, 'hasMore' => true,
        ]]);

        $page = $this->client->getReceivedMessages([
            'limit' => 2,
            'page' => 2,
            'since' => new \DateTimeImmutable('2026-10-30T08:00:00+02:00'),
            'phone' => '+40712345678',
            'deviceId' => 'device-1',
        ]);

        self::assertSame(
            'https://sms.example.test/api/v1/sms/received?limit=2&page=2&since=2026-10-30T08%3A00%3A00.000%2B02%3A00&phone=%2B40712345678&deviceId=device-1',
            $this->transport->lastRequest()['url'],
        );
        self::assertCount(2, $page);
        self::assertSame(12, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->limit);
        self::assertTrue($page->hasMore);

        $first = $page->messages[0];
        self::assertSame('r1', $first->id);
        self::assertSame('+40712345678', $first->sender);
        self::assertSame('Thanks!', $first->message);
        self::assertSame('Store phone', $first->deviceName);
        self::assertSame(1, $first->simSlot);
        self::assertNull($first->subscriptionId);

        $second = iterator_to_array($page)[1];
        self::assertInstanceOf(ReceivedMessage::class, $second);
        self::assertNull($second->message);
        self::assertTrue($second->isEncrypted);
        self::assertSame('b64', $second->encryptedPayload);
    }

    public function testGetReceivedMessagesWithoutFiltersHasNoQuery(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => ['messages' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'hasMore' => false]]);

        $page = $this->client->getReceivedMessages();

        self::assertSame('https://sms.example.test/api/v1/sms/received', $this->transport->lastRequest()['url']);
        self::assertCount(0, $page);
    }

    public function testGetReceivedMessagesRefusesAnUnknownFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown filter "from"');

        $this->client->getReceivedMessages(['from' => '+40712345678']);
    }

    public function testGetDevicesListsThePhones(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'devices' => [
                ['id' => 'd1', 'name' => 'Store phone', 'isOnline' => true, 'lastSeenAt' => '2026-11-01T15:15:45.349Z'],
                ['id' => 'd2', 'name' => 'Old Samsung', 'isOnline' => false],
            ],
            'online' => 1,
            'total' => 2,
        ]]);

        $devices = $this->client->getDevices();

        self::assertSame('https://sms.example.test/api/v1/devices', $this->transport->lastRequest()['url']);
        self::assertCount(2, $devices);
        self::assertSame(1, $devices->online);
        self::assertSame(2, $devices->total);
        self::assertSame('Store phone', $devices->devices[0]->name);
        self::assertTrue($devices->devices[0]->isOnline);
        self::assertSame('2026-11-01T15:15:45.349Z', $devices->devices[0]->lastSeenAt);
        self::assertNull($devices->devices[1]->lastSeenAt);
        self::assertInstanceOf(Device::class, $devices->find('d2'));
        self::assertNull($devices->find('nope'));
    }

    public function testGetUsageReadsTheQuota(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'plan' => 'pro',
            'accountType' => 'standard',
            'usage' => ['smsClean' => 15, 'smsBranded' => 0, 'twoFaClean' => 4],
            'limits' => ['smsClean' => 'unlimited', 'twoFaClean' => 100],
            'remaining' => ['smsClean' => 'unlimited', 'twoFaClean' => 96],
            'enterprise' => ['id' => 'e1', 'name' => 'Acme'],
        ]]);

        $usage = $this->client->getUsage();

        self::assertSame('https://sms.example.test/api/v1/account/usage', $this->transport->lastRequest()['url']);
        self::assertSame('pro', $usage->plan);
        self::assertSame('standard', $usage->accountType);
        self::assertSame(15, $usage->usage['smsClean']);
        self::assertSame('unlimited', $usage->limits['smsClean']);
        self::assertSame(96, $usage->remaining['twoFaClean']);
        self::assertSame(['id' => 'e1', 'name' => 'Acme'], $usage->enterprise);
    }

    public function testUsageWithoutACompanyHasNoEnterprise(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => ['plan' => 'free', 'accountType' => 'standard']]);

        self::assertNull($this->client->getUsage()->enterprise);
    }
}
