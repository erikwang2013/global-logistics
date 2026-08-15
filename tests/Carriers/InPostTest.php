<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\InPost;
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

final class InPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://api-shipx-pl.easypack24.net/v1/tracking/632085946340007012341234', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/inpost/track.json'));
        };

        $adapter = new InPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('632085946340007012341234');

        $this->assertSame('inpost', $tracking->carrierCode);
        $this->assertSame('632085946340007012341234', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('confirmed', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Kraków', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[1]->status);
        $this->assertSame('delivered', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('delivered', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'status' => 'delivered',
                'tracking_details' => [
                    [
                        'status' => 'DOR',
                        'origin_status' => 'delivered',
                        'agency' => 'Poznań',
                        'datetime' => '2026-08-14T12:10:00.000+02:00',
                    ],
                    [
                        'status' => 'PPN',
                        'origin_status' => 'confirmed',
                        'agency' => 'Kraków',
                        'datetime' => '2026-08-12T09:00:00.000+02:00',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new InPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('632085946340007012341234');

        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/inpost/empty.json'));

        $adapter = new InPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('632085946340007012341234');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/inpost/error.json'));

        $adapter = new InPost(new Config([]), $http);

        try {
            $adapter->queryTrack('632085946340007012341234');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[INPOST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new InPost(new Config([]), $http);

        try {
            $adapter->queryTrack('632085946340007012341234');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[INPOST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new InPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('632085946340007012341234');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new InPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('inpost createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('632085946340007012341234'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('inpost createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('inpost subscribe 待实现', $e->getMessage());
        }
    }
}
