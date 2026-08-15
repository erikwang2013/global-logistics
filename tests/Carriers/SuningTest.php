<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Suning;
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

final class SuningTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            parse_str($request->getUri()->getQuery(), $query);
            $this->assertSame('suning.logistics.crossbuytask.get', $query['appMethod']);
            $this->assertSame('json', $query['format']);
            $this->assertSame('test-app-key', $query['appKey']);
            $this->assertSame('v1.2', $query['versionNo']);
            $this->assertNotEmpty($query['signInfo']);
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $body = (string) $request->getBody();
            $payload = json_decode($body, true);
            $this->assertSame('62000000000000000001',
                $payload['sn_request']['sn_body']['queryCrossbuyTask']['logisticExpressId']);
            $this->assertSame(
                md5('test-app-secret' . $query['appMethod'] . $query['appRequestTime']
                    . $query['appKey'] . $query['versionNo'] . base64_encode($body)),
                $query['signInfo']
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/suning/track.json'));
        };

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret', 'version_no' => 'v1.2']]),
            $http,
        );

        $tracking = $adapter->queryTrack('62000000000000000001');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('已揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 10:20:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('已签收', $tracking->latestDescription);
        $this->assertSame('2026-08-15 16:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('已签收', $tracking->rawStatus);
        $this->assertSame('suning', $tracking->carrierCode);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'sn_responseContent' => [
                    'sn_head' => ['returnMessage' => 'biz.handler.data-get:success'],
                    'sn_body' => [
                        'queryCrossbuyTask' => [
                            ['statusDescription' => '已签收', 'siteDescription' => '杭州站点',
                                'realCompleteDate' => '2026-08-15', 'realCompleteTime' => '16:45:00'],
                            ['statusDescription' => '已揽收', 'siteDescription' => '南京苏宁物流基地',
                                'realCompleteDate' => '2026-08-12', 'realCompleteTime' => '10:20:00'],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('62000000000000000001');

        $this->assertSame('2026-08-12 10:20:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], '{}');

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('62000000000000000001');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[SUNING 403]', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'sn_responseContent' => [
                    'sn_error' => ['error_code' => 'sys.check.app-sign:error', 'error_msg' => '签名信息错误'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter2 = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('62000000000000000001');
            $this->fail('expected AuthException for sign error code');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[SUNING sys.check.app-sign:error]', $e->getMessage());
        }
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('62000000000000000001');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[SUNING 500]', $e->getMessage());
        }
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/suning/empty.json'));

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('62000000000000000001');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/suning/error.json'));

        $adapter = new Suning(
            new Config(['suning' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('62000000000000000001');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString(
                '[SUNING biz.logistics.querylogisticsstatus.missing-parameter:startTime]', $e->getMessage());
        }
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Suning(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SUNING createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('62000000000000000001'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SUNING createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SUNING subscribe 待实现', $e->getMessage());
        }
    }
}
