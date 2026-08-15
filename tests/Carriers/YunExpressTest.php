<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\YunExpress;
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

final class YunExpressTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): YunExpress
    {
        return new YunExpress(
            new Config([
                'yunexpress' => [
                    'app_id' => 'test-app-id',
                    'app_secret' => 'test-app-secret',
                    'source_key' => 'test-source-key',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParsesWithSignedHeaders(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $uri = (string) $request->getUri();

            if (str_contains($uri, '/openapi/oauth2/token')) {
                $this->assertSame('POST', $request->getMethod());
                $body = json_decode((string) $request->getBody(), true);
                $this->assertSame('client_credentials', $body['grantType'] ?? null);
                $this->assertSame('test-app-id', $body['appId'] ?? null);
                $this->assertSame('test-app-secret', $body['appSecret'] ?? null);
                $this->assertSame('test-source-key', $body['sourceKey'] ?? null);

                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"accessToken": "test-token", "expiresIn": 3600}');
            }

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/track-service/info/get?order_number=YT1234567890123',
                $request->getUri()->getPath() . '?' . $request->getUri()->getQuery());
            $this->assertSame('test-token', $request->getHeaderLine('token'));
            $this->assertSame('zh-CN', $request->getHeaderLine('Accept-Language'));
            $this->assertSame('application/json;charset=utf-8', $request->getHeaderLine('Content-Type'));

            $date = $request->getHeaderLine('date');
            $this->assertNotSame('', $date);
            $expectedSign = base64_encode(hash_hmac('sha256',
                'body=&date=' . $date . '&method=GET&uri=/v1/track-service/info/get?order_number=YT1234567890123',
                'test-app-secret', true));
            $this->assertSame($expectedSign, $request->getHeaderLine('sign'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yunexpress/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('YT1234567890123');

        $this->assertSame('yunexpress', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('Shipment picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Shenzhen', $tracking->events[0]->location);
        $this->assertSame('2026-08-13 09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Delivered', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Los Angeles', $tracking->events[2]->location);
        $this->assertSame('2026-08-15 18:30:00', $tracking->deliveredAt?->format('Y-m-d H:i:s'));
        $this->assertSame('0', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/openapi/oauth2/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken": "test-token"}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                json_encode([
                    'code' => 0,
                    'message' => 'success',
                    'data' => [
                        'track_info' => [
                            [
                                'track_events' => [
                                    ['event_date' => '2026-08-15 18:30:00', 'location' => 'LA', 'description' => 'Delivered'],
                                    ['event_date' => '2026-08-13 09:00:00', 'location' => 'SZ', 'description' => 'Shipment picked up'],
                                ],
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('YT1234567890123');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('2026-08-13 09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/openapi/oauth2/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken": "test-token"}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yunexpress/empty.json'));
        };

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('YT1234567890123');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/openapi/oauth2/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken": "test-token"}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yunexpress/error.json'));
        };

        try {
            $this->makeAdapter($http)->queryTrack('YT1234567890123');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertInstanceOf(LogisticsException::class, $e);
            $this->assertStringContainsString('[YUNEXPRESS 10001] waybill not found', $e->getMessage());
        }
    }

    public function testUnauthorizedThrowsAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/openapi/oauth2/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"accessToken": "test-token"}');
            }

            return new Response(401, [], '{"code": 401, "message": "unauthorized"}');
        };

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YUNEXPRESS 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('YT1234567890123');
    }

    public function testTokenFailureThrowsAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{"code": 401, "message": "bad credentials"}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YUNEXPRESS 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('YT1234567890123');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('YUNEXPRESS createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('YT1234567890123'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('YUNEXPRESS createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('YUNEXPRESS subscribe 待实现', $e->getMessage());
        }
    }
}
