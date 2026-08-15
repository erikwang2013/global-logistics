<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Ukrposhta;
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

final class UkrposhtaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://www.ukrposhta.ua/status-tracking/0.0.1/statuses?barcode=RA067022855UA&lang=en',
                (string) $request->getUri()
            );
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('Bearer test-bearer', $request->getHeaderLine('Authorization'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ukrposhta/track.json'));
        };

        $adapter = new Ukrposhta(new Config(['ukrposhta' => ['api_key' => 'test-bearer']]), $http);

        $tracking = $adapter->queryTrack('RA067022855UA');

        $this->assertSame('ukrposhta', $tracking->carrierCode);
        $this->assertSame('RA067022855UA', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Acceptance', $tracking->events[0]->description);
        $this->assertSame('2026-08-10 09:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('01001 KYIV 1', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Arrival to the branch office', $tracking->events[2]->description);
        $this->assertSame('Delivery', $tracking->latestDescription);
        $this->assertSame('2026-08-14 10:12:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Delivery', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                ['barcode' => 'RA067022855UA', 'step' => 4, 'date' => '2026-08-14T10:12:00',
                    'event' => 41000, 'eventName' => 'Delivery', 'index' => '79000', 'name' => 'LVIV 3'],
                ['barcode' => 'RA067022855UA', 'step' => 1, 'date' => '2026-08-10T09:15:00',
                    'event' => 10100, 'eventName' => 'Acceptance', 'index' => '01001', 'name' => 'KYIV 1'],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ukrposhta(new Config(['ukrposhta' => ['api_key' => 'test-bearer']]), $http);

        $tracking = $adapter->queryTrack('RA067022855UA');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Acceptance', $tracking->events[0]->description);
        $this->assertSame('Delivery', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ukrposhta/empty.json'));

        $adapter = new Ukrposhta(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RA067022855UA');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ukrposhta/error.json'));

        $adapter = new Ukrposhta(new Config([]), $http);

        try {
            $adapter->queryTrack('RA067022855UA');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[UKRPOSHTA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Ukrposhta(new Config([]), $http);

        try {
            $adapter->queryTrack('RA067022855UA');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[UKRPOSHTA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Ukrposhta(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RA067022855UA');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Ukrposhta(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ukrposhta createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA067022855UA'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ukrposhta createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ukrposhta subscribe 待实现', $e->getMessage());
        }
    }
}
