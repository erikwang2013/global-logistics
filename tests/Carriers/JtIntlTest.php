<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\JtIntl;
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

final class JtIntlTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://openapi.jtexpress.com.cn/API/External_GetTraces.json', (string) $request->getUri());
            $this->assertSame('ak1', $request->getHeaderLine('ApiKey'));
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('JT1234567890123', (string) $request->getBody());
            $this->assertStringContainsString('"msg_type":"GET_TRACES"', (string) $request->getBody());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/jt-intl/track.json'));
        };

        $adapter = new JtIntl(new Config(['jt-intl' => ['api_key' => 'ak1', 'secret' => 's1']]), $http);

        $tracking = $adapter->queryTrack('JT1234567890123');

        $this->assertSame('jt-intl', $tracking->carrierCode);
        $this->assertSame('JT1234567890123', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('上海分拨中心', $tracking->events[0]->location);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('已签收', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'success' => true,
                'code' => '0',
                'data' => [
                    [
                        'traces' => [
                            [
                                'track_time' => '2026-08-14 10:45:00',
                                'station_name' => '新加坡分拨中心',
                                'track_desc' => '快件已签收',
                                'status' => '已签收',
                            ],
                            [
                                'track_time' => '2026-08-10 09:12:00',
                                'station_name' => '上海分拨中心',
                                'track_desc' => '快件已揽收',
                                'status' => '已揽收',
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new JtIntl(new Config([]), $http);

        $tracking = $adapter->queryTrack('JT1234567890123');

        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jt-intl/empty.json'));

        $adapter = new JtIntl(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('JT1234567890123');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jt-intl/error.json'));

        $adapter = new JtIntl(new Config([]), $http);

        try {
            $adapter->queryTrack('JT1234567890123');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[JT-INTL 4001] 业务错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new JtIntl(new Config([]), $http);

        try {
            $adapter->queryTrack('JT1234567890123');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[JT-INTL 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new JtIntl(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('JT1234567890123');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new JtIntl(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('jt-intl createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('JT1234567890123'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('jt-intl createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('jt-intl subscribe 待实现', $e->getMessage());
        }
    }
}
