<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Bring;
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

final class BringTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://tracking.bring.com/api/v2/tracking.json?q=RB123456789NO', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('test-api-key', $request->getHeaderLine('X-MyBring-API-Key'));
            $this->assertSame('https://client.example.com/', $request->getHeaderLine('X-Bring-Client-URL'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/bring/track.json'));
        };

        $adapter = new Bring(
            new Config(['bring' => [
                'api_key' => 'test-api-key',
                'client_url' => 'https://client.example.com/',
            ]]),
            $http,
        );

        $tracking = $adapter->queryTrack('RB123456789NO');

        $this->assertSame('bring', $tracking->carrierCode);
        $this->assertSame('RB123456789NO', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('The shipment is picked up', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('The shipment has been delivered', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('DELIVERED', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'consignmentSet' => [
                    ['packageSet' => [
                        ['eventSet' => [
                            [
                                'description' => 'The shipment has been delivered',
                                'status' => 'DELIVERED',
                                'unitId' => 'STOCKHOLM',
                                'dateAndTime' => '2026-08-15T10:00:00+02:00',
                            ],
                            [
                                'description' => 'The shipment is picked up',
                                'status' => 'PICKED_UP',
                                'unitId' => 'OSLO',
                                'dateAndTime' => '2026-08-12T09:00:00+02:00',
                            ],
                        ]],
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Bring(new Config(['bring' => []]), $http);

        $tracking = $adapter->queryTrack('RB123456789NO');

        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/bring/empty.json'));

        $adapter = new Bring(new Config(['bring' => []]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RB123456789NO');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/bring/error.json'));

        $adapter = new Bring(new Config(['bring' => []]), $http);

        try {
            $adapter->queryTrack('RB123456789NO');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[BRING 500] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Bring(new Config(['bring' => []]), $http);

        try {
            $adapter->queryTrack('RB123456789NO');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[BRING 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Bring(new Config(['bring' => []]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RB123456789NO');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Bring(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bring createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RB123456789NO'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bring createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bring subscribe 待实现', $e->getMessage());
        }
    }
}
