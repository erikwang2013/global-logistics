<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DhlTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/mydhlapi/auth') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}');
            }

            $this->assertSame('Bearer tok-dhl', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('trackingNumber=DHL1234567890', $request->getUri()->getQuery());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dhl/track.json'));
        };

        $adapter = new Dhl(
            new Config(['dhl' => ['client_id' => 'test-dhl-client-id', 'client_secret' => 'test-dhl-client-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('DHL1234567890');

        $this->assertSame('dhl', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Frankfurt, DE', $tracking->events[0]->location);
        $this->assertSame('Processed', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-15T18:30:00', $tracking->events[1]->occurredAt?->format('Y-m-d\TH:i:s'));
    }

    public function testQueryTrackThrowsWhenNoShipment(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/mydhlapi/auth') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dhl/empty.json'));
        };

        $adapter = new Dhl(
            new Config(['dhl' => ['client_id' => 'test-dhl-client-id', 'client_secret' => 'test-dhl-client-secret']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('DHL1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/mydhlapi/auth') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"title":"Unauthorized"}');
        };

        $adapter = new Dhl(
            new Config(['dhl' => ['client_id' => 'test-dhl-client-id', 'client_secret' => 'test-dhl-client-secret']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[DHL 401] 认证失败');

        $adapter->queryTrack('DHL1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/mydhlapi/auth') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'], '"boom"');
        };

        $adapter = new Dhl(
            new Config(['dhl' => ['client_id' => 'test-dhl-client-id', 'client_secret' => 'test-dhl-client-secret']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[DHL] 响应解析失败');

        $adapter->queryTrack('DHL1234567890');
    }
}
