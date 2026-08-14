<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class EmsTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            parse_str((string) $request->getUri()->getQuery(), $query);
            $this->assertSame('test-app-id', $query['app_id'] ?? null);

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('EA123456789CN', $body['billNo'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ems/track.json'));
        };

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $tracking = $adapter->queryTrack('EA123456789CN');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('妥投', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackMapsPendingFromShouji(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'data' => [
                    'traces' => [
                        ['opTime' => '2026-08-14 10:00:00', 'opDesc' => '已收寄', 'opOrg' => '上海市'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $tracking = $adapter->queryTrack('EA123456789CN');

        $this->assertSame(TrackStatus::PENDING, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ems/empty.json'));

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('EA123456789CN');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ems/error.json'));

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[EMS 1001] 认证失败');

        $adapter->queryTrack('EA123456789CN');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('EA123456789CN');
    }

    public function testExceptionKeywordWinsOverDelivered(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'data' => [
                    'traces' => [
                        ['opTime' => '2026-08-15 18:30:00', 'opDesc' => '签收异常-收件人拒收', 'opOrg' => '杭州市'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ems(
            new Config(['ems' => ['app_id' => 'test-app-id']]),
            $http,
        );

        $tracking = $adapter->queryTrack('EA123456789CN');

        $this->assertSame(TrackStatus::EXCEPTION, $tracking->status);
    }
}
