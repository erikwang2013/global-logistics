<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Omniva;
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

final class OmnivaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://omniva.ee/api/tracking/CC401881072EE?lang=en', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/omniva/track.json'));
        };

        $adapter = new Omniva(new Config([]), $http);

        $tracking = $adapter->queryTrack('CC401881072EE');

        $this->assertSame('omniva', $tracking->carrierCode);
        $this->assertSame('CC401881072EE', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Package received', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Tallinn', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertSame('2026-08-14 11:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('DEL', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'events' => [
                    ['eventCode' => 'DEL', 'eventDescription' => 'Delivered', 'eventDate' => '2026-08-14T11:45:00+03:00'],
                    ['eventCode' => 'REG', 'eventDescription' => 'Package received', 'eventDate' => '2026-08-12T09:00:00+03:00'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Omniva(new Config([]), $http);

        $tracking = $adapter->queryTrack('CC401881072EE');

        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/omniva/empty.json'));

        $adapter = new Omniva(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('CC401881072EE');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/omniva/error.json'));

        $adapter = new Omniva(new Config([]), $http);

        try {
            $adapter->queryTrack('CC401881072EE');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[OMNIVA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Omniva(new Config([]), $http);

        try {
            $adapter->queryTrack('CC401881072EE');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[OMNIVA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Omniva(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('CC401881072EE');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Omniva(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('omniva createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('CC401881072EE'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('omniva createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('omniva subscribe 待实现', $e->getMessage());
        }
    }
}
