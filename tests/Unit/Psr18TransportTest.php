<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use SmsTunnel\Client;
use SmsTunnel\Exception\PermissionException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Tests\Support\RecordingClient;
use SmsTunnel\Transport\Psr18Transport;

final class Psr18TransportTest extends TestCase
{
    public function testTheClientSendsThroughAPsr18Client(): void
    {
        $http = new RecordingClient(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'messageId' => 'm-psr',
            'data' => ['messageId' => 'm-psr', 'branded' => false],
        ], \JSON_THROW_ON_ERROR)));
        $factory = new Psr17Factory();
        $client = new Client('sk_test_key', 'https://sms.example.test', new Psr18Transport($http, $factory, $factory));

        $result = $client->sendSms('+14155550123', 'Hi from PSR-18', 'device-1');

        self::assertSame('m-psr', $result->messageId);
        $request = $http->request;
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sms.example.test/api/v1/sms/send', (string) $request->getUri());
        self::assertSame('sk_test_key', $request->getHeaderLine('X-API-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"to":"+14155550123","message":"Hi from PSR-18","deviceId":"device-1"}', (string) $request->getBody());
    }

    public function testAGetHasNoBody(): void
    {
        $http = new RecordingClient(new Response(200, [], '{"success":true,"data":{"devices":[],"online":0,"total":0}}'));
        $factory = new Psr17Factory();
        $client = new Client('sk_test_key', transport: new Psr18Transport($http, $factory, $factory));

        self::assertCount(0, $client->getDevices());
        self::assertNotNull($http->request);
        self::assertSame('GET', $http->request->getMethod());
        self::assertSame('', (string) $http->request->getBody());
    }

    public function testErrorAnswersAndHeadersAreMapped(): void
    {
        $http = new RecordingClient(new Response(403, ['X-Trace' => ['a', 'b']], '{"message":"Missing permission","code":"PERMISSION_REQUIRED","permission":"sms:read"}'));
        $factory = new Psr17Factory();
        $client = new Client('sk_test_key', transport: new Psr18Transport($http, $factory, $factory));

        try {
            $client->getMessageStatus('m1');
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            self::assertSame('sms:read', $exception->getPermission());
            self::assertSame('a, b', $exception->getHeaders()['x-trace']);
        }
    }

    public function testANetworkErrorBecomesATransportException(): void
    {
        $http = new RecordingClient(new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        });
        $factory = new Psr17Factory();
        $client = new Client('sk_test_key', transport: new Psr18Transport($http, $factory, $factory));

        try {
            $client->getUsage();
            self::fail('No exception was thrown.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('connection refused', $exception->getMessage());
            self::assertInstanceOf(ClientExceptionInterface::class, $exception->getPrevious());
        }
    }
}
