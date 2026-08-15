<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Kerry;
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

final class KerryTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Kerry
    {
        return new Kerry(
            new Config([
                'kerry' => [
                    'app_id' => 'test-kerry-app-id',
                    'app_key' => 'test-kerry-app-key',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/auth/token')) {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
                $body = json_decode((string) $request->getBody(), true);
                $this->assertSame('test-kerry-app-id', $body['appId'] ?? null);
                $this->assertSame('test-kerry-app-key', $body['appKey'] ?? null);

                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"tok-kerry","expiresIn":3600}');
            }

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('Bearer tok-kerry', $request->getHeaderLine('Authorization'));
            $this->assertSame('barcode=KK0123456789TH', $request->getUri()->getQuery());
            $this->assertSame(
                'https://openapi.kerryexpress.com/track/v1/tracking/search',
                $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . $request->getUri()->getPath(),
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/kerry/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('KK0123456789TH');

        $this->assertSame('kerry', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Bangkok', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-10T09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Chiang Mai', $tracking->events[3]->location);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-12T11:10:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('DELIVERED', $tracking->rawStatus);
    }

    public function testQueryTrackReusesCachedToken(): void
    {
        $tokenCalls = 0;
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use (&$tokenCalls) {
            if (str_ends_with((string) $request->getUri(), '/auth/token')) {
                $tokenCalls++;

                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"tok-kerry","expiresIn":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/kerry/track.json'));
        };

        $adapter = $this->makeAdapter($http);
        $adapter->queryTrack('KK0123456789TH');
        $adapter->queryTrack('KK0123456789TH');

        $this->assertSame(1, $tokenCalls);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/auth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"tok-kerry","expiresIn":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/kerry/empty.json'));
        };

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('KK0123456789TH');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/auth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"tok-kerry","expiresIn":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/kerry/error.json'));
        };

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[KERRY TRACKING_NOT_FOUND] Barcode not found');

        $this->makeAdapter($http)->queryTrack('KK0123456789TH');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/auth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken":"tok-kerry","expiresIn":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"success":false,"errorCode":"E401"}');
        };

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[KERRY 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('KK0123456789TH');
    }

    public function testQueryTrackThrowsWhenTokenFails(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], 'oops');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[KERRY 500] token 获取失败');

        $this->makeAdapter($http)->queryTrack('KK0123456789TH');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('kerry createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('KK0123456789TH'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }
}
