<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Ane;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AneTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('test-app-key', $request->getHeaderLine('appKey'));
            $timestamp = $request->getHeaderLine('timestamp');
            $this->assertSame(md5('test-app-key' . $timestamp), $request->getHeaderLine('sign'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('ANE1234567890', $body['trackingNo']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ane/track.json'));
        };

        $adapter = new Ane(
            new Config(['ane' => ['app_key' => 'test-app-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('ANE1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('2026-08-14 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 18:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('200', $tracking->rawStatus);
        $this->assertSame('ane', $tracking->carrierCode);
    }

    public function testUnauthorizedThrowsAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{"code": 401, "msg": "unauthorized"}');

        $adapter = new Ane(
            new Config(['ane' => ['app_key' => 'test-app-key']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $adapter->queryTrack('ANE1234567890');
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Ane(
            new Config(['ane' => ['app_key' => 'test-app-key']]),
            $http,
        );

        try {
            $adapter->queryTrack('ANE1234567890');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertInstanceOf(LogisticsException::class, $e);
            $this->assertStringContainsString('[ANE 500]', $e->getMessage());
        }
    }

    public function testNoTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['code' => 200, 'msg' => '成功', 'data' => ['list' => []]], JSON_UNESCAPED_UNICODE));

        $adapter = new Ane(
            new Config(['ane' => ['app_key' => 'test-app-key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('ANE1234567890');
    }
}
