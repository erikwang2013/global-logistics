<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Yd;
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

final class YdTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            $this->assertSame('test-app-key', $query['app_key'] ?? null);
            $this->assertSame(md5('app_key' . 'test-app-key' . 'timestamp' . $query['timestamp'] . 'test-app-secret'), $query['sign'] ?? null);
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('YD1234567890', $body['trackingNumber'] ?? null);
            $this->assertSame('json', $body['format'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yd/track.json'));
        };

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YD1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd/empty.json'));

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('YD1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd/error.json'));

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YD 4001] 认证失败');

        $adapter->queryTrack('YD1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('YD1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $http = new FakeHttpClient();
        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
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
                'status' => '1',
                'data' => [
                    ['trackTime' => '2026-08-15 18:30:00', 'stationName' => '杭州市', 'trackDesc' => '签收异常-收件人拒收'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YT1234567890');

        $this->assertSame(TrackStatus::EXCEPTION, $tracking->status);
    }
}
