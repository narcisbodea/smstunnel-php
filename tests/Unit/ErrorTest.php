<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\PermissionException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Exception\SmsTunnelException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Tests\Support\FakeTransport;

final class ErrorTest extends TestCase
{
    /**
     * @return array<string, array{\Closure(Client): mixed}>
     */
    public static function calls(): array
    {
        return [
            'sendSms' => [static fn (Client $client): mixed => $client->sendSms('+14155550123', 'Hi')],
            'sendVerificationCode' => [static fn (Client $client): mixed => $client->sendVerificationCode('+14155550123', 'Code 123456')],
            'getMessageStatus' => [static fn (Client $client): mixed => $client->getMessageStatus('m1')],
            'getReceivedMessages' => [static fn (Client $client): mixed => $client->getReceivedMessages(['limit' => 5])],
            'getDevices' => [static fn (Client $client): mixed => $client->getDevices()],
            'getUsage' => [static fn (Client $client): mixed => $client->getUsage()],
        ];
    }

    /**
     * Every call, with every kind of error answer.
     *
     * @return iterable<string, array{\Closure(Client): mixed, int, array<string, mixed>|string|null, array<string, string>, class-string<ApiException>, string, ?string}>
     */
    public static function failures(): iterable
    {
        $errors = [
            '401' => [401, ['message' => 'Invalid or expired API key', 'error' => 'Unauthorized', 'statusCode' => 401], [], AuthenticationException::class, 'Invalid or expired API key', null],
            '403 permission' => [
                403,
                ['message' => 'This API key may not read received texts', 'error' => 'Forbidden', 'statusCode' => 403, 'code' => 'PERMISSION_REQUIRED', 'permission' => 'sms:inbox'],
                [],
                PermissionException::class,
                'This API key may not read received texts',
                'PERMISSION_REQUIRED',
            ],
            '429' => [429, ['message' => 'ThrottlerException: Too Many Requests', 'statusCode' => 429], ['Retry-After' => '42'], RateLimitException::class, 'ThrottlerException: Too Many Requests', null],
            '400 list' => [400, ['message' => ['Phone number must contain 7-25 characters', 'message must be longer than or equal to 1 characters'], 'error' => 'Bad Request', 'statusCode' => 400], [], ApiException::class, 'Phone number must contain 7-25 characters; message must be longer than or equal to 1 characters', null],
            '404' => [404, ['message' => 'Message not found', 'error' => 'Not Found', 'statusCode' => 404], [], ApiException::class, 'Message not found', null],
            '500 error only' => [500, ['error' => 'Internal Server Error', 'statusCode' => 500], [], ApiException::class, 'Internal Server Error', null],
            '500 html' => [500, "<html><body><h1>500 Internal Server Error</h1>\n<hr>nginx</body></html>", [], ApiException::class, '500 Internal Server Error nginx', null],
            '503 empty' => [503, null, [], ApiException::class, 'SMSTunnel returned error: 503', null],
            '200 not json' => [200, 'OK', [], ApiException::class, 'not JSON', null],
            '200 success false' => [200, ['success' => false, 'error' => 'Device not found'], [], ApiException::class, 'Device not found', null],
            '301 redirect' => [301, '', ['Location' => 'https://smstunnel.io/api/v1/devices'], ApiException::class, 'redirect to https://smstunnel.io/api/v1/devices', null],
        ];

        foreach (self::calls() as $callName => [$call]) {
            foreach ($errors as $errorName => [$status, $body, $headers, $class, $message, $code]) {
                yield "{$callName} {$errorName}" => [$call, $status, $body, $headers, $class, $message, $code];
            }
        }
    }

    /**
     * @param \Closure(Client): mixed                $call
     * @param array<string, mixed>|string|null       $body
     * @param array<string, string>                  $headers
     * @param class-string<ApiException>             $class
     */
    #[DataProvider('failures')]
    public function testEveryCallThrowsTheMatchingException(
        \Closure $call,
        int $status,
        array|string|null $body,
        array $headers,
        string $class,
        string $message,
        ?string $code,
    ): void {
        $transport = (new FakeTransport())->respond($status, $body, $headers);
        $client = new Client('sk_test_key', 'https://sms.example.test', $transport);

        try {
            $call($client);
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame($class, $exception::class);
            self::assertInstanceOf(SmsTunnelException::class, $exception);
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame($status, $exception->getCode());
            self::assertSame($code, $exception->getErrorCode());
        }
    }

    public function testAPermissionErrorNamesTheMissingPermission(): void
    {
        $transport = (new FakeTransport())->respond(403, ['message' => 'Missing permission', 'code' => 'PERMISSION_REQUIRED', 'permission' => 'sms:inbox']);
        $client = new Client('sk_test_key', transport: $transport);

        try {
            $client->getReceivedMessages();
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            self::assertSame('sms:inbox', $exception->getPermission());
            self::assertIsArray($exception->getBody());
        }
    }

    public function testAPlanErrorHasNoPermission(): void
    {
        $transport = (new FakeTransport())->respond(403, ['message' => 'Your plan does not include this', 'statusCode' => 403]);
        $client = new Client('sk_test_key', transport: $transport);

        try {
            $client->sendSms('+14155550123', 'Hi');
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            self::assertNull($exception->getPermission());
            self::assertNull($exception->getErrorCode());
        }
    }

    public function testARateLimitSaysHowLongToWait(): void
    {
        $transport = (new FakeTransport())
            ->respond(429, ['message' => 'Too Many Requests'], ['retry-after' => '42'])
            ->respond(429, ['message' => 'Too Many Requests'], ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 120) . ' GMT'])
            ->respond(429, ['message' => 'Too Many Requests']);
        $client = new Client('sk_test_key', transport: $transport);

        $waits = [];
        for ($i = 0; $i < 3; ++$i) {
            try {
                $client->sendSms('+14155550123', 'Hi');
            } catch (RateLimitException $exception) {
                $waits[] = $exception->getRetryAfter();
            }
        }

        self::assertSame(42, $waits[0]);
        self::assertIsInt($waits[1]);
        self::assertGreaterThan(100, $waits[1]);
        self::assertLessThanOrEqual(120, $waits[1]);
        self::assertNull($waits[2]);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('calls')]
    public function testEveryCallLetsATransportErrorThrough(\Closure $call): void
    {
        $transport = (new FakeTransport())->fail(new TransportException('Could not reach SMSTunnel: timeout'));
        $client = new Client('sk_test_key', transport: $transport);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('timeout');

        $call($client);
    }

    public function testTheBodyAndHeadersStayOnTheException(): void
    {
        $transport = (new FakeTransport())->respond(500, ['message' => 'Boom', 'traceId' => 't-1'], ['X-Request-Id' => 'req-1']);
        $client = new Client('sk_test_key', transport: $transport);

        try {
            $client->getDevices();
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(['message' => 'Boom', 'traceId' => 't-1'], $exception->getBody());
            self::assertSame('req-1', $exception->getHeaders()['x-request-id']);
        }
    }

    public function testFromResponseCanBeUsedOnItsOwn(): void
    {
        self::assertInstanceOf(AuthenticationException::class, ApiException::fromResponse(401, null));
        self::assertSame('SMSTunnel returned error: 418', ApiException::fromResponse(418, ['message' => ''])->getMessage());
        self::assertSame(str_repeat('é', 300), ApiException::fromResponse(500, str_repeat('é', 400))->getMessage());
    }
}
