<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\ChinaPost;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ChinaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            $params = [];
            parse_str((string) $request->getBody(), $params);
            $this->assertSame('test-app-id', $params['appId']);
            $this->assertSame('RA123456789CN', json_decode($params['requestData'], true)['mailNo']);
            $this->assertSame(
                strtoupper(md5('test-app-id' . $params['requestData'] . $params['timestamp'] . 'test-app-secret')),
                $params['sign']
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/china-post/track.json'));
        };

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('RA123456789CN');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('【北京市】已收寄', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('【杭州市】快递已妥投，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 11:40:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('【杭州市】快递已妥投，签收人：本人', $tracking->rawStatus);
        $this->assertSame('china-post', $tracking->carrierCode);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'message' => '成功',
                'data' => [
                    'traces' => [
                        ['acceptTime' => '2026-08-15 11:40:00', 'acceptAddress' => '杭州市', 'remark' => '快递已妥投'],
                        ['acceptTime' => '2026-08-12 09:15:00', 'acceptAddress' => '北京市', 'remark' => '已收寄'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('RA123456789CN');

        $this->assertSame('2026-08-12 09:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{"code": "10001", "message": "unauthorized"}');

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('RA123456789CN');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CHINA-POST 401]', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['code' => '10002', 'message' => '签名错误'], JSON_UNESCAPED_UNICODE));

        $adapter2 = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('RA123456789CN');
            $this->fail('expected AuthException for auth error code');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CHINA-POST 10002]', $e->getMessage());
        }
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('RA123456789CN');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CHINA-POST 500]', $e->getMessage());
        }
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/china-post/empty.json'));

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RA123456789CN');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/china-post/error.json'));

        $adapter = new ChinaPost(
            new Config(['china-post' => ['app_id' => 'test-app-id', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('RA123456789CN');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CHINA-POST 20001]', $e->getMessage());
        }
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new ChinaPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CHINA-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new \GlobalLogistics\Models\Order('RA123456789CN'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CHINA-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CHINA-POST subscribe 待实现', $e->getMessage());
        }
    }
}
