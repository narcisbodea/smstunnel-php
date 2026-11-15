<?php

declare(strict_types=1);

namespace SmsTunnel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmsTunnel\Client;
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\PermissionException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Response\ScheduledText;
use SmsTunnel\Response\ScheduledTexts;
use SmsTunnel\Response\SendResult;
use SmsTunnel\Tests\Support\FakeTransport;

/**
 * Scheduled texts: `sendSms(..., sendAt: ...)`, `listScheduled()`, `getScheduled()` and `cancelScheduled()`.
 */
final class ScheduledTextTest extends TestCase
{
    private const TO = '+14155550123';
    private const TEXT = 'Reminder: your appointment is tomorrow at 10:30.';
    private const ID = '6b0a1c2d3e4f5a6b7c8d9e0f';

    /** One scheduled text as SMSTunnel answers it (SMSserver scheduled-texts.service.ts apiView). */
    private const ITEM = [
        'id' => self::ID,
        'to' => self::TO,
        'message' => self::TEXT,
        'sendAt' => '2026-11-16T07:00:00.000Z',
        'status' => 'scheduled',
        'deviceId' => null,
        'messageId' => null,
        'error' => null,
        'createdAt' => '2026-11-15T10:12:44.120Z',
    ];
    private const SCHEDULED = [
        'success' => true,
        'scheduled' => true,
        'scheduledId' => self::ID,
        'sendAt' => '2026-11-16T07:00:00.000Z',
        'data' => self::ITEM,
    ];

    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('sk_test_key', 'https://sms.example.test', $this->transport);
    }

    public function testADateTimeGoesOutWithItsOwnOffsetAndTheAnswerIsAScheduledText(): void
    {
        $this->transport->respond(200, self::SCHEDULED);
        $when = new \DateTimeImmutable('2026-11-16 09:00:00', new \DateTimeZone('Europe/Bucharest'));

        $text = $this->client->sendSms(self::TO, self::TEXT, sendAt: $when);

        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/send', $request['url']);
        self::assertSame(['to' => self::TO, 'message' => self::TEXT, 'sendAt' => '2026-11-16T09:00:00+02:00'], $this->transport->lastJson());

        self::assertInstanceOf(ScheduledText::class, $text);
        self::assertSame(self::ID, $text->id);
        self::assertSame(self::TO, $text->to);
        self::assertSame(self::TEXT, $text->message);
        self::assertSame('2026-11-16T07:00:00.000Z', $text->sendAt);
        self::assertSame(ScheduledText::SCHEDULED, $text->status);
        self::assertNull($text->deviceId);
        self::assertNull($text->messageId);
        self::assertNull($text->error);
        self::assertSame('2026-11-15T10:12:44.120Z', $text->createdAt);
        self::assertSame(self::ITEM, $text->raw);
        self::assertFalse($text->replayed);
    }

    public function testARetryWithTheSameKeyGetsTheFirstScheduledTextBack(): void
    {
        $this->transport->respond(200, self::SCHEDULED, ['Idempotent-Replayed' => 'true']);

        $text = $this->client->sendSms(self::TO, self::TEXT, idempotencyKey: 'booking-881-reminder', sendAt: '2026-11-16T07:00:00Z');

        self::assertTrue($text->replayed, 'this call scheduled no new text');
        self::assertSame(self::ID, $text->id);
        self::assertSame('booking-881-reminder', $this->transport->lastRequest()['headers']['Idempotency-Key']);
    }

    /**
     * @return array<string, array{\DateTimeInterface, string}>
     */
    public static function moments(): array
    {
        return [
            'UTC' => [new \DateTimeImmutable('2026-11-16 07:00:00', new \DateTimeZone('UTC')), '2026-11-16T07:00:00+00:00'],
            'a negative offset' => [new \DateTimeImmutable('2026-11-16 02:00:00', new \DateTimeZone('America/New_York')), '2026-11-16T02:00:00-05:00'],
            'a half-hour offset' => [new \DateTimeImmutable('2026-11-16 12:30:00', new \DateTimeZone('Asia/Kolkata')), '2026-11-16T12:30:00+05:30'],
            'a fixed offset' => [new \DateTimeImmutable('2026-11-16T09:00:00+02:00'), '2026-11-16T09:00:00+02:00'],
            'fractions of a second dropped' => [new \DateTimeImmutable('2026-11-16 07:00:00.750', new \DateTimeZone('UTC')), '2026-11-16T07:00:00+00:00'],
            'a mutable DateTime' => [new \DateTime('2026-11-16 09:00:00', new \DateTimeZone('Europe/Bucharest')), '2026-11-16T09:00:00+02:00'],
        ];
    }

    #[DataProvider('moments')]
    public function testEveryDateTimeGoesOutInRfc3339WithItsOffset(\DateTimeInterface $when, string $sent): void
    {
        $this->transport->respond(200, self::SCHEDULED);

        $this->client->sendSms(self::TO, self::TEXT, sendAt: $when);

        self::assertSame($sent, $this->transport->lastJson()['sendAt']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validStrings(): array
    {
        return [
            'UTC' => ['2026-11-16T07:00:00Z', '2026-11-16T07:00:00Z'],
            'an offset' => ['2026-11-16T09:00:00+02:00', '2026-11-16T09:00:00+02:00'],
            'a negative offset' => ['2026-11-16T02:00:00-05:00', '2026-11-16T02:00:00-05:00'],
            'milliseconds' => ['2026-11-16T07:00:00.250Z', '2026-11-16T07:00:00.250Z'],
            'no seconds' => ['2026-11-16T09:00+02:00', '2026-11-16T09:00+02:00'],
            'spaces around' => ["  2026-11-16T07:00:00Z\n", '2026-11-16T07:00:00Z'],
        ];
    }

    #[DataProvider('validStrings')]
    public function testAnIsoStringGoesOutAsItIs(string $when, string $sent): void
    {
        $this->transport->respond(200, self::SCHEDULED);

        $text = $this->client->sendSms(self::TO, self::TEXT, sendAt: $when);

        self::assertSame($sent, $this->transport->lastJson()['sendAt']);
        self::assertSame(self::ID, $text->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidStrings(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'a date only' => ['2026-11-16'],
            'no offset' => ['2026-11-16T09:00:00'],
            'a space instead of T' => ['2026-11-16 09:00:00+02:00'],
            'words' => ['tomorrow 9am'],
            'microseconds' => ['2026-11-16T07:00:00.123456Z'],
            'an offset without a colon' => ['2026-11-16T09:00:00+0200'],
            'lower case' => ['2026-11-16t07:00:00z'],
            'a timestamp' => ['1794812400'],
        ];
    }

    #[DataProvider('invalidStrings')]
    public function testAStringSmsTunnelCannotTakeThrowsBeforeAnythingIsSent(string $when): void
    {
        try {
            $this->client->sendSms(self::TO, self::TEXT, sendAt: $when);
            self::fail('The time was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('sendAt must be an ISO 8601 date and time with its offset', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testWithoutSendAtTheAnswerIsASendResultAsBefore(): void
    {
        $this->transport->respond(200, ['success' => true, 'messageId' => 'm1', 'queued' => true, 'data' => ['messageId' => 'm1', 'queued' => true]]);

        $result = $this->client->sendSms(self::TO, self::TEXT, sendAt: null);

        self::assertInstanceOf(SendResult::class, $result);
        self::assertSame('m1', $result->messageId);
        self::assertTrue($result->queued);
        self::assertArrayNotHasKey('sendAt', $this->transport->lastJson());
    }

    public function testEveryOtherArgumentGoesOutWithSendAt(): void
    {
        $this->transport->respond(200, self::SCHEDULED);

        $this->client->sendSms('0721123456', self::TEXT, 'device-1', '+40', 'booking-881-reminder', '2026-11-16T09:00:00+02:00');

        $request = $this->transport->lastRequest();
        self::assertSame('booking-881-reminder', $request['headers']['Idempotency-Key']);
        self::assertSame(
            ['to' => '0721123456', 'message' => self::TEXT, 'deviceId' => 'device-1', 'defaultCountryCode' => '+40', 'sendAt' => '2026-11-16T09:00:00+02:00'],
            $this->transport->lastJson(),
        );
    }

    public function testAnAnswerWithoutDataStillGivesTheIdAndTheTime(): void
    {
        $this->transport->respond(200, ['success' => true, 'scheduled' => true, 'scheduledId' => self::ID, 'sendAt' => '2026-11-16T07:00:00.000Z']);

        $text = $this->client->sendSms(self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z');

        self::assertSame(self::ID, $text->id);
        self::assertSame('2026-11-16T07:00:00.000Z', $text->sendAt);
        self::assertSame(ScheduledText::SCHEDULED, $text->status);
    }

    public function testListScheduledAsksForTheWaitingOnesByDefault(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'items' => [self::ITEM, ['id' => '6b0a1c2d3e4f5a6b7c8d9e10', 'deviceId' => 'device-1'] + self::ITEM],
            'total' => 2,
            'page' => 1,
            'limit' => 50,
            'waiting' => 7,
            'max' => 200,
        ]]);

        $page = $this->client->listScheduled();

        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/scheduled?scope=waiting&page=1&limit=50', $request['url']);
        self::assertNull($request['body']);
        self::assertArrayNotHasKey('Content-Type', $request['headers']);

        self::assertCount(2, $page);
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->page);
        self::assertSame(50, $page->limit);
        self::assertSame(7, $page->waiting, 'texts waiting in the whole account');
        self::assertSame(200, $page->max, 'the plan\'s cap');
        self::assertSame($page->items, iterator_to_array($page));
        self::assertSame(self::ID, $page->items[0]->id);
        self::assertSame('device-1', $page->items[1]->deviceId);
    }

    public function testListScheduledReadsTheDoneOnes(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => [
            'items' => [
                ['status' => 'sent', 'messageId' => '6b0a1c2d3e4f5a6b7c8d9eaa'] + self::ITEM,
                ['status' => 'failed', 'error' => 'limit_reached'] + self::ITEM,
                ['status' => 'canceled'] + self::ITEM,
            ],
            'total' => 23, 'page' => 2, 'limit' => 3, 'waiting' => 0, 'max' => 10,
        ]]);

        $page = $this->client->listScheduled(ScheduledTexts::DONE, 2, 3);

        self::assertSame('https://sms.example.test/api/v1/sms/scheduled?scope=done&page=2&limit=3', $this->transport->lastRequest()['url']);
        [$sent, $failed, $canceled] = $page->items;
        self::assertSame(ScheduledText::SENT, $sent->status);
        self::assertSame('6b0a1c2d3e4f5a6b7c8d9eaa', $sent->messageId);
        self::assertSame(ScheduledText::FAILED, $failed->status);
        self::assertSame('limit_reached', $failed->error);
        self::assertSame(ScheduledText::CANCELED, $canceled->status);
        self::assertSame(23, $page->total);
    }

    public function testListScheduledRefusesAnUnknownScope(): void
    {
        try {
            $this->client->listScheduled('sent');
            self::fail('The scope was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Unknown scope "sent". Use "waiting" or "done".', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testAnEmptyListHasNoItems(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'waiting' => 0, 'max' => 10]]);

        self::assertCount(0, $this->client->listScheduled());
    }

    public function testGetScheduledReadsOneText(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => ['status' => 'sent', 'messageId' => '6b0a1c2d3e4f5a6b7c8d9eaa'] + self::ITEM]);

        $text = $this->client->getScheduled(' ' . self::ID . ' ');

        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/scheduled/' . self::ID, $request['url']);
        self::assertNull($request['body']);
        self::assertSame(ScheduledText::SENT, $text->status);
        self::assertSame('6b0a1c2d3e4f5a6b7c8d9eaa', $text->messageId, 'for getMessageStatus()');
    }

    public function testTheIdIsEscapedInThePath(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => self::ITEM]);

        $this->client->getScheduled('../status/x?y=1');

        self::assertSame('https://sms.example.test/api/v1/sms/scheduled/..%2Fstatus%2Fx%3Fy%3D1', $this->transport->lastRequest()['url']);
    }

    public function testCancelScheduledSendsADeleteWithoutABody(): void
    {
        $this->transport->respond(200, ['success' => true, 'data' => ['status' => 'canceled'] + self::ITEM]);

        $text = $this->client->cancelScheduled(self::ID);

        $request = $this->transport->lastRequest();
        self::assertSame('DELETE', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/scheduled/' . self::ID, $request['url']);
        self::assertNull($request['body']);
        self::assertArrayNotHasKey('Content-Type', $request['headers']);
        self::assertArrayNotHasKey('Idempotency-Key', $request['headers']);
        self::assertSame('sk_test_key', $request['headers']['X-API-Key']);
        self::assertSame(ScheduledText::CANCELED, $text->status);
    }

    /**
     * @return array<string, array{\Closure(Client): mixed}>
     */
    public static function idCalls(): array
    {
        return [
            'getScheduled' => [static fn (Client $client): mixed => $client->getScheduled('  ')],
            'cancelScheduled' => [static fn (Client $client): mixed => $client->cancelScheduled('')],
        ];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('idCalls')]
    public function testAnEmptyIdThrowsBeforeAnythingIsSent(\Closure $call): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The scheduled text id is empty.');

        $call($this->client);
    }

    /**
     * SMSTunnel's refusals of a scheduled text (SMSserver scheduled-texts.rules.ts), each with the exception it becomes.
     *
     * @return array<string, array{int, string, class-string<ApiException>}>
     */
    public static function refusals(): array
    {
        return [
            'too soon' => [400, 'SCHEDULE_TOO_SOON', ApiException::class],
            'too far' => [400, 'SCHEDULE_TOO_FAR', ApiException::class],
            'invalid input' => [400, 'SCHEDULE_INVALID_INPUT', ApiException::class],
            'text too long' => [400, 'SCHEDULE_TEXT_TOO_LONG', ApiException::class],
            'number not allowed' => [400, 'SCHEDULE_NUMBER_NOT_ALLOWED', ApiException::class],
            'sealed-only phone' => [400, 'SCHEDULE_SEALED_ONLY_PHONE', ApiException::class],
            'phone not found' => [404, 'SCHEDULE_PHONE_NOT_FOUND', ApiException::class],
            'limit reached' => [403, 'SCHEDULE_LIMIT_REACHED', PermissionException::class],
        ];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('refusals')]
    public function testARefusedScheduledTextKeepsItsCode(int $status, string $code, string $class): void
    {
        $labels = [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found'];
        $body = ['statusCode' => $status, 'error' => $labels[$status], 'code' => $code, 'message' => 'Words for ' . $code];
        if ($code === 'SCHEDULE_LIMIT_REACHED') {
            $body['max'] = 10;
        }
        $this->transport->respond($status, $body);

        try {
            $this->client->sendSms(self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z');
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame($class, $exception::class);
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame($code, $exception->getErrorCode());
            self::assertSame('Words for ' . $code, $exception->getMessage());
            self::assertSame($body, $exception->getBody());
        }
    }

    public function testAFullPlanSaysItsCap(): void
    {
        $this->transport->respond(403, [
            'statusCode' => 403,
            'error' => 'Forbidden',
            'code' => 'SCHEDULE_LIMIT_REACHED',
            'message' => 'You have as many scheduled texts as your plan allows. Wait until some go out, or cancel one.',
            'max' => 50,
        ]);

        try {
            $this->client->sendSms(self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z');
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            $body = $exception->getBody();
            self::assertIsArray($body);
            self::assertSame(50, $body['max']);
            self::assertNull($exception->getPermission());
        }
    }

    public function testTooManyRequestsIsARateLimit(): void
    {
        $this->transport->respond(429, ['statusCode' => 429, 'message' => 'ThrottlerException: Too Many Requests'], ['Retry-After' => '12']);

        try {
            $this->client->sendSms(self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z');
            self::fail('No exception was thrown.');
        } catch (RateLimitException $exception) {
            self::assertSame(12, $exception->getRetryAfter());
        }
    }

    public function testAServerWithoutScheduledTextsGivesItsOwn400(): void
    {
        // What SMSTunnel answered before scheduled texts: its validation refuses the unknown field.
        $this->transport->respond(400, ['message' => ['property sendAt should not exist'], 'error' => 'Bad Request', 'statusCode' => 400]);

        try {
            $this->client->sendSms(self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z');
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(400, $exception->getStatusCode());
            self::assertNull($exception->getErrorCode());
            self::assertSame('property sendAt should not exist', $exception->getMessage());
        }
    }

    public function testATextThisKeyDidNotScheduleIsNotFound(): void
    {
        $this->transport->respond(404, ['statusCode' => 404, 'error' => 'Not Found', 'code' => 'SCHEDULE_NOT_FOUND', 'message' => 'This scheduled text no longer exists.']);

        try {
            $this->client->getScheduled(self::ID);
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('SCHEDULE_NOT_FOUND', $exception->getErrorCode());
        }
    }

    public function testATextThatAlreadyWentOutCannotBeCanceled(): void
    {
        $this->transport->respond(409, [
            'statusCode' => 409,
            'error' => 'Conflict',
            'code' => 'SCHEDULE_NOT_EDITABLE',
            'message' => 'This text has already gone out or was canceled, or was scheduled through the API (cancel it instead).',
        ]);

        try {
            $this->client->cancelScheduled(self::ID);
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('SCHEDULE_NOT_EDITABLE', $exception->getErrorCode());
            self::assertNull($exception->getRetryAfter());
        }
    }

    public function testListingNeedsTheReadPermission(): void
    {
        $this->transport->respond(403, ['statusCode' => 403, 'error' => 'Forbidden', 'code' => 'PERMISSION_REQUIRED', 'permission' => 'sms:read', 'message' => 'This API key may not read texts.']);

        try {
            $this->client->listScheduled();
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            self::assertSame('PERMISSION_REQUIRED', $exception->getErrorCode());
            self::assertSame('sms:read', $exception->getPermission());
        }
    }
}
