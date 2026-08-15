<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Gls;
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

final class GlsTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Gls
    {
        return new Gls(
            new Config(['gls' => ['api_key' => 'test-gls-key']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertStringContainsString('https://api.gls-group.eu/tracking/v1/track/GLS1234567890', (string) $request->getUri());
            $this->assertSame('test-gls-key', $request->getHeaderLine('apikey'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/gls/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('GLS1234567890');

        $this->assertSame('gls', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('Parcel data received', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Berlin', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-13T09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('In transit', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Leipzig', $tracking->events[1]->location);
        $this->assertSame('Delivered', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Berlin', $tracking->events[2]->location);
        $this->assertSame('2026-08-15T10:00:00', $tracking->events[2]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-15T10:00:00', $tracking->deliveredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testQueryTrackWorksWithoutApiKey(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('', $request->getHeaderLine('apikey'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/gls/track.json'));
        };

        $adapter = new Gls(new Config(['gls' => []]), $http);

        $tracking = $adapter->queryTrack('GLS1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoParcels(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/gls/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('GLS1234567890');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[GLS 403] 认证失败');

        $this->makeAdapter($http)->queryTrack('GLS1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], 'oops');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[GLS 500] 接口错误');

        $this->makeAdapter($http)->queryTrack('GLS1234567890');
    }

    public function testMapStatusVariants(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'parcels' => [
                    [
                        'parcelNumber' => 'GLS1234567890',
                        'events' => [
                            ['eventCode' => '2000', 'eventDescription' => 'Parcel data received', 'location' => 'Berlin', 'eventDate' => '2026-08-10', 'eventTime' => '01:00:00'],
                            ['eventCode' => '4000', 'eventDescription' => 'Out for delivery', 'location' => 'Berlin', 'eventDate' => '2026-08-11', 'eventTime' => '02:00:00'],
                            ['eventCode' => '5000', 'eventDescription' => 'Held', 'location' => 'Berlin', 'eventDate' => '2026-08-12', 'eventTime' => '03:00:00'],
                            ['eventCode' => '6000', 'eventDescription' => 'Exception', 'location' => 'Berlin', 'eventDate' => '2026-08-13', 'eventTime' => '04:00:00'],
                            ['eventCode' => '7000', 'eventDescription' => 'Returned', 'location' => 'Berlin', 'eventDate' => '2026-08-14', 'eventTime' => '05:00:00'],
                            ['eventCode' => '8000', 'eventDescription' => 'Parcel arrived at depot', 'location' => 'Berlin', 'eventDate' => '2026-08-15', 'eventTime' => '06:00:00'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('GLS1234567890');

        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[3]->status);
        $this->assertSame(TrackStatus::RETURNED, $tracking->events[4]->status);
        // 未命中关键词的其余描述归为 IN_TRANSIT
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[5]->status);
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
            $this->assertStringContainsString('gls createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('GLS1234567890'));
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
