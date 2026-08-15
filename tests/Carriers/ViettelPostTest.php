<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\ViettelPost;
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

final class ViettelPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://www.viettelpost.com.vn/CurrentTrackByOrderCode?orderCode=RR123456789VN', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/viettel-post/track.json'));
        };

        $adapter = new ViettelPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789VN');

        $this->assertSame('viettel-post', $tracking->carrierCode);
        $this->assertSame('RR123456789VN', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Đã nhận hàng', $tracking->events[0]->description);
        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Hà Nội', $tracking->events[0]->location);
        $this->assertSame('Đã giao hàng thành công', $tracking->latestDescription);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('DELIVERED', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'result' => [
                    'data' => [
                        'tracking' => [
                            [
                                'time' => '2026-08-14 10:45:00',
                                'location' => 'TP. Hồ Chí Minh',
                                'description' => 'Đã giao hàng thành công',
                                'status' => 'DELIVERED',
                            ],
                            [
                                'time' => '2026-08-10 09:12:00',
                                'location' => 'Hà Nội',
                                'description' => 'Đã nhận hàng',
                                'status' => 'RECEIVED',
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new ViettelPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789VN');

        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/viettel-post/empty.json'));

        $adapter = new ViettelPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789VN');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/viettel-post/error.json'));

        $adapter = new ViettelPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789VN');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[VIETTEL-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new ViettelPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789VN');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[VIETTEL-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new ViettelPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789VN');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new ViettelPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('viettel-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789VN'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('viettel-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('viettel-post subscribe 待实现', $e->getMessage());
        }
    }
}
