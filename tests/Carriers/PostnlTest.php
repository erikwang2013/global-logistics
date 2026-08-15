<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Postnl;
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

final class PostnlTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('test-api-key', $request->getHeaderLine('apikey'));
            $this->assertStringContainsString('/status/3SABCD1234567', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/postnl/track.json'));
        };

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('3SABCD1234567');

        $this->assertSame('postnl', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Rotterdam', $tracking->events[0]->location);
        $this->assertSame('2026-08-10T09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        // 描述 'Out for delivery' 优先于 numeric code 3（分拣）=> OUT_FOR_DELIVERY
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Amsterdam', $tracking->events[3]->location);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('4', $tracking->rawStatus);
    }

    public function testQueryTrackFallsBackToShipmentStatus(): void
    {
        $body = json_encode([
            'status' => ['code' => 4, 'description' => 'Delivered'],
            'events' => [
                ['code' => 'A22', 'description' => 'Some other wording', 'location' => ['city' => 'Amsterdam'], 'timeStamp' => '2026-08-14T10:00:00+02:00'],
            ],
        ], JSON_THROW_ON_ERROR);

        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], $body);

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('3SABCD1234567');

        $this->assertSame(TrackStatus::UNKNOWN, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{"error":"Unauthorized"}');

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[POSTNL 401] 认证失败');

        $adapter->queryTrack('3SABCD1234567');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], '{"error":"Internal"}');

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[POSTNL 500] 接口错误');

        $adapter->queryTrack('3SABCD1234567');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/postnl/empty.json'));

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('3SABCD1234567');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[POSTNL] 响应解析失败');

        $adapter->queryTrack('3SABCD1234567');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new Postnl(
            new Config(['postnl' => ['api_key' => 'test-api-key']]),
            new FakeHttpClient(),
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('postnl createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('3SABCD1234567'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('postnl createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('postnl subscribe 待实现', $e->getMessage());
        }
    }
}
