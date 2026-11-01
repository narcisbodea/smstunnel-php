<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Transport\CurlTransport;

/**
 * CurlTransport against a real socket: PHP's built-in server with an echo router.
 */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $router = \dirname(__DIR__) . '/Fixtures/echo-server.php';
        $process = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            self::fail('Could not start the PHP built-in server.');
        }
        self::$server = $process;
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (\is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        self::fail('The PHP built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testItSendsMethodHeadersAndBody(): void
    {
        $response = (new CurlTransport())->send(
            'POST',
            self::$baseUrl . '/api/v1/sms/send',
            ['X-API-Key' => 'sk_test_key', 'Content-Type' => 'application/json'],
            '{"to":"+14155550123","message":"Hi"}',
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('yes', $response->header('X-Echo'));
        self::assertSame('application/json', $response->header('content-type'));

        $echo = self::echoOf($response->body);
        self::assertSame('POST', $echo['method']);
        self::assertSame('/api/v1/sms/send', $echo['uri']);
        self::assertSame('sk_test_key', $echo['headers']['x-api-key']);
        self::assertSame('application/json', $echo['headers']['content-type']);
        self::assertSame('{"to":"+14155550123","message":"Hi"}', $echo['body']);
    }

    public function testALongBodyIsSentWithoutExpectContinue(): void
    {
        $body = json_encode(['to' => '+14155550123', 'message' => str_repeat('a', 1600)], \JSON_THROW_ON_ERROR);

        $response = (new CurlTransport())->send('POST', self::$baseUrl . '/echo', ['Content-Type' => 'application/json'], $body);

        self::assertSame(200, $response->statusCode);
        $echo = self::echoOf($response->body);
        self::assertSame($body, $echo['body']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testTheClientWorksOverCurl(): void
    {
        $client = new Client('sk_test_key', self::$baseUrl);

        $devices = $client->getDevices();

        self::assertSame('GET', $devices->raw['method']);
        self::assertSame('/api/v1/devices', $devices->raw['uri']);
    }

    public function testA401BecomesAnAuthenticationException(): void
    {
        $client = new Client('sk_test_key', self::$baseUrl . '/fail-401');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Status 401');

        $client->getUsage();
    }

    public function testA429KeepsItsRetryAfterHeader(): void
    {
        $client = new Client('sk_test_key', self::$baseUrl . '/fail-429');

        try {
            $client->sendSms('+14155550123', 'Hi');
            self::fail('No exception was thrown.');
        } catch (RateLimitException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame(7, $exception->getRetryAfter());
        }
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $response = (new CurlTransport())->send('GET', self::$baseUrl . '/redirect', ['X-API-Key' => 'sk_test_key'], null);

        self::assertSame(301, $response->statusCode);
        self::assertSame('https://smstunnel.io/', $response->header('location'));
    }

    public function testAClosedPortIsATransportError(): void
    {
        $port = self::freePort();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach SMSTunnel at http://127.0.0.1:' . $port);

        (new CurlTransport(timeout: 3, connectTimeout: 2))->send('GET', 'http://127.0.0.1:' . $port . '/api/v1/devices', [], null);
    }

    public function testATimeoutIsATransportError(): void
    {
        $this->expectException(TransportException::class);

        // 10.255.255.1 is not routable, so the connection never opens.
        (new CurlTransport(timeout: 0.5, connectTimeout: 0.3))->send('GET', 'http://10.255.255.1/', [], null);
    }

    /**
     * @return array{method: string, uri: string, headers: array<string, string>, body: string}
     */
    private static function echoOf(string $body): array
    {
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data']);

        /** @var array{method: string, uri: string, headers: array<string, string>, body: string} */
        return $decoded['data'];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::fail('Could not find a free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
