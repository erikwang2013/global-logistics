<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Aramex;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AramexTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Aramex
    {
        return new Aramex(
            new Config([
                'aramex' => [
                    'user_name' => 'test-aramex-user',
                    'password' => 'test-aramex-pass',
                    'account_number' => 'ACC-123',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://ws.aramex.net/trackingapi/api/v1/track', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('test-aramex-user', $body['userName'] ?? null);
            $this->assertSame('test-aramex-pass', $body['password'] ?? null);
            $this->assertSame('ACC-123', $body['accountNumber'] ?? null);
            $this->assertSame('ARAMEX1234567890', $body['trackingNumbers'][0]['trackingNumber'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/aramex/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');

        $this->assertSame('aramex', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('Shipment collected', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Dubai', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-13T09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('In transit', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Delivered', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Dubai', $tracking->events[2]->location);
        $this->assertSame('2026-08-14T18:30:00', $tracking->events[2]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T18:30:00', $tracking->deliveredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsWhenNoResults(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/aramex/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[ARAMEX 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], 'oops');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ARAMEX 500] 接口错误');

        $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ARAMEX] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');
    }

    public function testMapStatusVariants(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'results' => [
                    [
                        'trackingNumber' => 'ARAMEX1234567890',
                        'events' => [
                            ['eventCode' => 'SH1001', 'eventDescription' => 'Received', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-10', 'eventTime' => '01:00:00'],
                            ['eventCode' => 'SH1002', 'eventDescription' => 'Out for delivery', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-11', 'eventTime' => '02:00:00'],
                            ['eventCode' => 'SH1003', 'eventDescription' => 'Exception', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-12', 'eventTime' => '03:00:00'],
                            ['eventCode' => 'SH1004', 'eventDescription' => 'Failed', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-13', 'eventTime' => '04:00:00'],
                            ['eventCode' => 'SH1005', 'eventDescription' => 'On hold', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-14', 'eventTime' => '05:00:00'],
                            ['eventCode' => 'SH1006', 'eventDescription' => 'Return', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-15', 'eventTime' => '06:00:00'],
                            ['eventCode' => 'SH1007', 'eventDescription' => 'Customs clearance', 'eventLocation' => 'Dubai', 'eventDate' => '2026-08-16', 'eventTime' => '07:00:00'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('ARAMEX1234567890');

        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[3]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[4]->status);
        $this->assertSame(TrackStatus::RETURNED, $tracking->events[5]->status);
        // 未命中关键词的其余描述归为 IN_TRANSIT
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[6]->status);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertNull($tracking->deliveredAt);
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('aramex createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('ARAMEX1234567890'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }
}
