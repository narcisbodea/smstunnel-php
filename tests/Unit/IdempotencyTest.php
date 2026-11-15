<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Response\SendResult;
use SmsTunnel\Tests\Support\FakeTransport;

/**
 * `$idempotencyKey`: the `Idempotency-Key` header that makes a retried send safe.
 */
final class IdempotencyTest extends TestCase
{
    private const TO = '+14155550123';

    /** The server's answers (SMSserver public-api/idempotency/idempotency.service.ts). */
    private const REUSED = [
        'statusCode' => 422,
        'error' => 'Unprocessable Entity',
        'code' => 'IDEMPOTENCY_KEY_REUSED',
        'message' => 'This Idempotency-Key was already used in the last 24 hours for a different request (another endpoint, recipient, text, phone or country code). Use a new key for a new text.',
    ];
    private const IN_PROGRESS = [
        'statusCode' => 409,
        'error' => 'Conflict',
        'code' => 'IDEMPOTENCY_KEY_IN_PROGRESS',
        'message' => 'A request with this Idempotency-Key is still being processed. Retry in a moment to get its result.',
    ];
    private const SENT = [
        'success' => true,
        'messageId' => '6ae7957285431f6150ce52fc',
        'data' => ['messageId' => '6ae7957285431f6150ce52fc', 'branded' => false, 'remaining' => ['clean' => 95, 'total' => 'unlimited']],
    ];

    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('sk_test_key', 'https://sms.example.test', $this->transport);
    }

    /**
     * Both send calls, each with the text it takes, and the path it posts to.
     *
     * @return array<string, array{\Closure(Client, ?string): SendResult, string}>
     */
    public static function sends(): array
    {
        return [
            'sendSms' => [
                static fn (Client $client, ?string $key): SendResult => $client->sendSms(self::TO, 'Your order #1042 has shipped.', idempotencyKey: $key),
                '/api/v1/sms/send',
            ],
            'sendVerificationCode' => [
                static fn (Client $client, ?string $key): SendResult => $client->sendVerificationCode(self::TO, 'Your sign-in code is 482913', idempotencyKey: $key),
                '/api/v1/sms/send-2fa',
            ],
        ];
    }

    /**
     * @param \Closure(Client, ?string): SendResult $send
     */
    #[DataProvider('sends')]
    public function testTheKeyGoesOutAsTheIdempotencyKeyHeader(\Closure $send, string $path): void
    {
        $this->transport->respond(200, self::SENT);

        $send($this->client, 'order-1042-shipped');

        $request = $this->transport->lastRequest();
        self::assertSame('https://sms.example.test' . $path, $request['url']);
        self::assertSame('order-1042-shipped', $request['headers']['Idempotency-Key']);
        self::assertSame('sk_test_key', $request['headers']['X-API-Key']);
        self::assertArrayNotHasKey('idempotencyKey', $this->transport->lastJson(), 'a header, not a field of the body');
    }

    /**
     * @param \Closure(Client, ?string): SendResult $send
     */
    #[DataProvider('sends')]
    public function testNoKeyNoHeader(\Closure $send, string $path): void
    {
        $this->transport->respond(200, self::SENT)->respond(200, self::SENT);

        $send($this->client, null);
        $this->client->sendSms(self::TO, 'Hi');

        self::assertCount(2, $this->transport->requests);
        foreach ($this->transport->requests as $request) {
            self::assertSame([], array_filter(array_keys($request['headers']), static fn (string $name): bool => strtolower($name) === 'idempotency-key'));
        }
        self::assertSame('https://sms.example.test' . $path, $this->transport->requests[0]['url']);
    }

    public function testTheKeyWorksWithTheOtherArgumentsByPosition(): void
    {
        $this->transport->respond(200, self::SENT);

        $this->client->sendSms('0721123456', 'Hi', 'device-1', '+40', 'order-1042-shipped');

        self::assertSame('order-1042-shipped', $this->transport->lastRequest()['headers']['Idempotency-Key']);
        self::assertSame(['to' => '0721123456', 'message' => 'Hi', 'deviceId' => 'device-1', 'defaultCountryCode' => '+40'], $this->transport->lastJson());
    }

    public function testARetryAfterNoAnswerSendsTheSameKeyAndGetsTheFirstAnswer(): void
    {
        // The first try reached SMSTunnel, but its answer was lost; the retry gets the first answer back.
        $this->transport
            ->fail(new TransportException('Could not reach SMSTunnel at https://sms.example.test: timeout'))
            ->respond(200, self::SENT, ['Idempotent-Replayed' => 'true']);
        $key = 'b3f1c0de-5d7a-4c2e-9f4b-0a1b2c3d4e5f';

        try {
            $this->client->sendSms(self::TO, 'Your order #1042 has shipped.', idempotencyKey: $key);
            self::fail('No exception was thrown.');
        } catch (TransportException) {
        }
        self::assertCount(1, $this->transport->requests, 'the client never retries on its own');

        $result = $this->client->sendSms(self::TO, 'Your order #1042 has shipped.', idempotencyKey: $key);

        [$first, $retry] = $this->transport->requests;
        self::assertSame($key, $first['headers']['Idempotency-Key']);
        self::assertSame($key, $retry['headers']['Idempotency-Key']);
        self::assertSame($first['body'], $retry['body']);
        self::assertSame('6ae7957285431f6150ce52fc', $result->messageId);
        self::assertTrue($result->replayed, 'the retry sent no new text');
    }

    /**
     * @param \Closure(Client, ?string): SendResult $send
     */
    #[DataProvider('sends')]
    public function testAFirstAnswerIsNotReplayed(\Closure $send, string $path): void
    {
        $this->transport->respond(200, self::SENT)->respond(200, self::SENT);

        self::assertFalse($send($this->client, 'order-1042-shipped')->replayed);
        self::assertFalse($send($this->client, null)->replayed);
        self::assertSame('https://sms.example.test' . $path, $this->transport->lastRequest()['url']);
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function replayHeaders(): array
    {
        return [
            'true' => [['Idempotent-Replayed' => 'true'], true],
            'any case and spaces' => [['idempotent-replayed' => ' True '], true],
            'false' => [['Idempotent-Replayed' => 'false'], false],
            'empty' => [['Idempotent-Replayed' => ''], false],
            'none' => [[], false],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('replayHeaders')]
    public function testReplayedComesFromTheIdempotentReplayedHeader(array $headers, bool $replayed): void
    {
        $this->transport->respond(200, self::SENT, $headers)->respond(200, self::SENT, $headers);

        self::assertSame($replayed, $this->client->sendSms(self::TO, 'Hi', idempotencyKey: 'order-1042-shipped')->replayed);
        self::assertSame($replayed, $this->client->sendVerificationCode(self::TO, 'Your sign-in code is 482913', idempotencyKey: 'sign-in-77')->replayed);
    }

    /**
     * @return array<string, array{\Closure(FakeTransport): FakeTransport}>
     */
    public static function failures(): array
    {
        return [
            'no answer' => [static fn (FakeTransport $transport): FakeTransport => $transport->fail(new TransportException('timeout'))],
            '409 in progress' => [static fn (FakeTransport $transport): FakeTransport => $transport->respond(409, self::IN_PROGRESS, ['Retry-After' => '1'])],
            '429 too many requests' => [static fn (FakeTransport $transport): FakeTransport => $transport->respond(429, ['message' => 'Too Many Requests'], ['Retry-After' => '5'])],
            '500 server error' => [static fn (FakeTransport $transport): FakeTransport => $transport->respond(500, ['statusCode' => 500, 'message' => 'Internal server error'])],
        ];
    }

    /**
     * @param \Closure(FakeTransport): FakeTransport $queue
     */
    #[DataProvider('failures')]
    public function testTheClientNeverRetriesOnItsOwn(\Closure $queue): void
    {
        $queue($this->transport);

        try {
            $this->client->sendSms(self::TO, 'Hi', idempotencyKey: 'order-1042-shipped');
            self::fail('No exception was thrown.');
        } catch (ApiException|TransportException) {
        }

        self::assertCount(1, $this->transport->requests);
    }

    /**
     * @param \Closure(Client, ?string): SendResult $send
     */
    #[DataProvider('sends')]
    public function testAKeyUsedForAnotherTextIsA422WithItsCode(\Closure $send, string $path): void
    {
        $this->transport->respond(422, self::REUSED);

        try {
            $send($this->client, 'order-1042-shipped');
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame(422, $exception->getCode());
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->getErrorCode());
            self::assertSame(self::REUSED['message'], $exception->getMessage());
            self::assertSame(self::REUSED, $exception->getBody());
            self::assertNull($exception->getRetryAfter());
        }
        self::assertSame('https://sms.example.test' . $path, $this->transport->lastRequest()['url']);
    }

    public function testAKeyStillInProgressIsA409ThatSaysWhenToTryAgain(): void
    {
        $this->transport->respond(409, self::IN_PROGRESS, ['Retry-After' => '1']);

        try {
            $this->client->sendSms(self::TO, 'Hi', idempotencyKey: 'order-1042-shipped');
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertNotInstanceOf(RateLimitException::class, $exception);
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('IDEMPOTENCY_KEY_IN_PROGRESS', $exception->getErrorCode());
            self::assertSame(1, $exception->getRetryAfter());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validKeys(): array
    {
        return [
            'one character' => ['a'],
            'own id' => ['order-1042-shipped'],
            'uuid' => ['b3f1c0de-5d7a-4c2e-9f4b-0a1b2c3d4e5f'],
            'inner spaces' => ['order 1042 shipped'],
            '255 characters' => ['!' . str_repeat('x', 253) . '~'],
            'quotes' => ['"quoted"'],
        ];
    }

    #[DataProvider('validKeys')]
    public function testAValidKeyIsSentAsItIs(string $key): void
    {
        $this->transport->respond(200, self::SENT);

        $this->client->sendSms(self::TO, 'Hi', idempotencyKey: $key);

        self::assertSame($key, $this->transport->lastRequest()['headers']['Idempotency-Key']);
    }

    /**
     * Every key SMSTunnel can't take, with both send calls.
     *
     * @return iterable<string, array{\Closure(Client, ?string): SendResult, string}>
     */
    public static function invalidKeys(): iterable
    {
        $keys = [
            'empty' => '',
            'spaces only' => '   ',
            'leading space' => ' order-1042',
            'trailing space' => 'order-1042 ',
            '256 characters' => str_repeat('x', 256),
            'not ascii' => 'comandă-1042',
            'tab' => "order\t1042",
            'trailing newline' => "order-1042\n",
            'header injection' => "order\r\nX-Other: 1",
            'delete character' => "order\x7f",
            'nul byte' => "order\x001042",
        ];

        foreach (self::sends() as $sendName => [$send]) {
            foreach ($keys as $keyName => $key) {
                yield "{$sendName} {$keyName}" => [$send, $key];
            }
        }
    }

    /**
     * @param \Closure(Client, ?string): SendResult $send
     */
    #[DataProvider('invalidKeys')]
    public function testAKeySmsTunnelCannotTakeThrowsBeforeAnythingIsSent(\Closure $send, string $key): void
    {
        try {
            $send($this->client, $key);
            self::fail('The key was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('idempotency key must be 1 to 255 printable ASCII characters', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testRetryAfterIsOnEveryApiException(): void
    {
        $exception = ApiException::fromResponse(409, self::IN_PROGRESS, ['retry-after' => '1']);

        self::assertSame(ApiException::class, $exception::class);
        self::assertSame(1, $exception->getRetryAfter());
        self::assertNull(ApiException::fromResponse(500, null)->getRetryAfter());
        self::assertSame(42, ApiException::fromResponse(429, null, ['retry-after' => '42'])->getRetryAfter());
    }
}
