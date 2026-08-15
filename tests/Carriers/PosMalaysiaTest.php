<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\PosMalaysia;
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

final class PosMalaysiaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://apis.pos.com.my/apigateway/as2corporate/api/v2trackntracewebapijson/v1/?id=RR123456789MY&Culture=en',
                (string) $request->getUri()
            );
            $this->assertSame('test-key', $request->getHeaderLine('X-User-Key'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/pos-malaysia/track.json'));
        };

        $adapter = new PosMalaysia(new Config(['pos-malaysia' => ['user_key' => 'test-key']]), $http);

        $tracking = $adapter->queryTrack('RR123456789MY');

        $this->assertSame('pos-malaysia', $tracking->carrierCode);
        $this->assertSame('RR123456789MY', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Item dispatched out', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 10:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Pusat Mel Nasional', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Item delivered to JANE DOE', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Item delivered to JANE DOE', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                [
                    'date' => '14 Aug 2026, 12:10:00',
                    'process' => 'Item delivered to JANE DOE',
                    'office' => 'Pos Laju Shah Alam',
                ],
                [
                    'date' => '12 Aug 2026, 10:30:00',
                    'process' => 'Item dispatched out',
                    'office' => 'Pusat Mel Nasional',
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new PosMalaysia(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789MY');

        $this->assertSame('2026-08-12 10:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/pos-malaysia/empty.json'));

        $adapter = new PosMalaysia(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789MY');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/pos-malaysia/error.json'));

        $adapter = new PosMalaysia(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MY');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[POS-MALAYSIA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new PosMalaysia(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MY');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[POS-MALAYSIA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new PosMalaysia(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789MY');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new PosMalaysia(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pos-malaysia createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789MY'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pos-malaysia createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pos-malaysia subscribe 待实现', $e->getMessage());
        }
    }
}
