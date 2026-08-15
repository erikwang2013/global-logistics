<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\ChunghwaPost;
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

final class ChunghwaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://postserv.post.gov.tw/webpost/CSController?cmd=querymail&mailNo=RA123456789TW', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/chunghwa-post/track.json'));
        };

        $adapter = new ChunghwaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RA123456789TW');

        $this->assertSame('chunghwa-post', $tracking->carrierCode);
        $this->assertSame('RA123456789TW', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(1, $tracking->events);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[0]->status);
        $this->assertSame('已投遞成功', $tracking->events[0]->description);
        $this->assertSame('2026-08-15 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('臺北', $tracking->events[0]->location);
        $this->assertSame('已投遞成功', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('11', $tracking->rawStatus);
    }

    public function testQueryTrackFallsBackToReceiveTime(): void
    {
        // 适配器仅返回最新一笔状态（无轨迹时间线、无降序反转语义）；
        // DELIVERYDATE 缺失时退化为 RECEIVETIME，未送达状态不设置 deliveredAt。
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'STATUS' => '10',
                'MAILNO' => 'RA123456789TW',
                'TRACKINFO' => '運送中',
                'MAILTYPE' => '國際掛號郵件',
                'RECEIVETIME' => '2026-08-14 16:30:00',
                'LOCATIONNAME' => '臺北',
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new ChunghwaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RA123456789TW');

        $this->assertCount(1, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertSame('2026-08-14 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertNull($tracking->deliveredAt);
        $this->assertSame('10', $tracking->rawStatus);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/chunghwa-post/empty.json'));

        $adapter = new ChunghwaPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RA123456789TW');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/chunghwa-post/error.json'));

        $adapter = new ChunghwaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456789TW');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CHUNGHWA-POST 500] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new ChunghwaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456789TW');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CHUNGHWA-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new ChunghwaPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RA123456789TW');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new ChunghwaPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('chunghwa-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA123456789TW'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('chunghwa-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('chunghwa-post subscribe 待实现', $e->getMessage());
        }
    }
}
