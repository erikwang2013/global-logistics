<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Ymd;
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

final class YmdTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('test-partner-id', $request->getHeaderLine('partnerId'));
            $timestamp = $request->getHeaderLine('timestamp');
            $this->assertSame(md5('test-partner-id' . $timestamp . 'test-token'), $request->getHeaderLine('sign'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('YMD123456789', $body['waybillNo']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ymd/track.json'));
        };

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YMD123456789');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('货物已被提货', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 11:20:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('货物已签收', $tracking->latestDescription);
        $this->assertSame('2026-08-15 15:22:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('货物已签收', $tracking->rawStatus);
        $this->assertSame('ymd', $tracking->carrierCode);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0000',
                'msg' => '成功',
                'data' => [
                    'traces' => [
                        ['acceptTime' => '2026-08-15 15:22:00', 'acceptAddress' => '杭州市', 'remark' => '货物已签收'],
                        ['acceptTime' => '2026-08-12 11:20:00', 'acceptAddress' => '广州市', 'remark' => '货物已被提货'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YMD123456789');

        $this->assertSame('2026-08-12 11:20:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('YMD123456789');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[YMD 401]', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['code' => '10003', 'msg' => '认证失败'], JSON_UNESCAPED_UNICODE));

        $adapter2 = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('YMD123456789');
            $this->fail('expected AuthException for auth error code');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[YMD 10003]', $e->getMessage());
        }
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('YMD123456789');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[YMD 500]', $e->getMessage());
        }
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ymd/empty.json'));

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('YMD123456789');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ymd/error.json'));

        $adapter = new Ymd(
            new Config(['ymd' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('YMD123456789');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[YMD 20001]', $e->getMessage());
        }
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Ymd(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('YMD createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('YMD123456789'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('YMD createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('YMD subscribe 待实现', $e->getMessage());
        }
    }
}
