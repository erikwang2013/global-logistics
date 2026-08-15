<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Dpd;
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

final class DpdTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Dpd
    {
        return new Dpd(
            new Config([
                'dpd' => [
                    'user_name' => 'test-dpd-user',
                    'password' => 'test-dpd-pass',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertStringContainsString('https://api.dpd.it/shipment/track/DPD1234567890', (string) $request->getUri());
            $this->assertSame('Basic ' . base64_encode('test-dpd-user:test-dpd-pass'), $request->getHeaderLine('Authorization'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dpd/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('DPD1234567890');

        $this->assertSame('dpd', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('Parcel picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Milan', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-13T09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('In transit', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Bologna', $tracking->events[1]->location);
        $this->assertSame('Delivered', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Rome', $tracking->events[2]->location);
        $this->assertSame('2026-08-15T18:30:00', $tracking->events[2]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-15T18:30:00', $tracking->deliveredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/dpd/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('DPD1234567890');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[DPD 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('DPD1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], 'oops');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[DPD 500] 接口错误');

        $this->makeAdapter($http)->queryTrack('DPD1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[DPD] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('DPD1234567890');
    }

    public function testMapStatusVariants(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'trackingData' => [
                    'events' => [
                        ['eventCode' => 'C02', 'eventDescription' => 'Collection', 'eventLocation' => 'Milan', 'eventDate' => '2026-08-10 01:00:00'],
                        ['eventCode' => 'O01', 'eventDescription' => 'Out for delivery', 'eventLocation' => 'Rome', 'eventDate' => '2026-08-11 02:00:00'],
                        ['eventCode' => 'X01', 'eventDescription' => 'Exception', 'eventLocation' => 'Rome', 'eventDate' => '2026-08-12 03:00:00'],
                        ['eventCode' => 'R01', 'eventDescription' => 'Return', 'eventLocation' => 'Rome', 'eventDate' => '2026-08-13 04:00:00'],
                        ['eventCode' => 'T02', 'eventDescription' => 'Departed from terminal', 'eventLocation' => 'Bologna', 'eventDate' => '2026-08-14 05:00:00'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('DPD1234567890');

        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::RETURNED, $tracking->events[3]->status);
        // 未命中关键词的其余描述归为 IN_TRANSIT
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[4]->status);
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
            $this->assertStringContainsString('dpd createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('DPD1234567890'));
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
