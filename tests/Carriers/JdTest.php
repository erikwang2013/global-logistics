<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Jd;
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

final class JdTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('JD1234567890', $body['orderId'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/jd/track.json'));
        };

        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        $tracking = $adapter->queryTrack('JD1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jd/empty.json'));

        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('JD1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jd/error.json'));

        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[JD 401] 认证失败');

        $adapter->queryTrack('JD1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('JD1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $http = new FakeHttpClient();
        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('SF1234567890'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }

    public function testExceptionKeywordWinsOverDelivered(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'data' => [
                    'traces' => [
                        ['operateTime' => '2026-08-15 18:30:00', 'operateDesc' => '签收异常-收件人拒收', 'stationName' => '杭州市'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Jd(
            new Config(['jd' => ['app_key' => 'test']]),
            $http,
        );

        $tracking = $adapter->queryTrack('JD1234567890');

        $this->assertSame(TrackStatus::EXCEPTION, $tracking->status);
    }
}
