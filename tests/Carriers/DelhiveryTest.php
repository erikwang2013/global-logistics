<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Delhivery;
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

final class DelhiveryTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://track.delhivery.com/api/v1/packages/json/?waybill=334455667788990&token=test-token', (string) $request->getUri());
            $this->assertSame('Token test-token', $request->getHeaderLine('Authorization'));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/delhivery/track.json'));
        };

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('334455667788990');

        $this->assertSame('delhivery', $tracking->carrierCode);
        $this->assertSame('334455667788990', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Consignment Manifested', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 08:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Mumbai_Andheri (Maharashtra)', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Delivered to consignee', $tracking->latestDescription);
        $this->assertSame('2026-08-14 10:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('DL', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'ShipmentData' => [
                    [
                        'Shipment' => [
                            'StatusType' => 'DL',
                            'Scans' => [
                                ['ScanDetail' => [
                                    'ScanDateTime' => '2026-08-14T10:30:00.000000',
                                    'ScanType' => 'DL',
                                    'Status' => 'Delivered to consignee',
                                    'ScannedLocation' => 'Pune',
                                ]],
                                ['ScanDetail' => [
                                    'ScanDateTime' => '2026-08-12T08:15:00.000000',
                                    'ScanType' => 'UD',
                                    'Status' => 'Consignment Manifested',
                                    'ScannedLocation' => 'Mumbai',
                                ]],
                            ],
                        ],
                    ],
                ],
            ]));

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('334455667788990');

        $this->assertSame('2026-08-12 08:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/delhivery/empty.json'));

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('334455667788990');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/delhivery/error.json'));

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('334455667788990');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[DELHIVERY] Invalid waybill number', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('334455667788990');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[DELHIVERY 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Delhivery(
            new Config(['delhivery' => ['key' => 'test-token']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('334455667788990');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Delhivery(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('delhivery createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('334455667788990'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('delhivery createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('delhivery subscribe 待实现', $e->getMessage());
        }
    }
}
