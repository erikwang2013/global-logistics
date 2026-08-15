<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\ThailandPost;
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

final class ThailandPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
                $this->assertSame('application/json', $request->getHeaderLine('Accept'));

                $tokenBody = json_decode((string) $request->getBody(), true);
                $this->assertSame('test-app-token', $tokenBody['token']);

                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://trackapi.thailandpost.co.th/post/api/v1/track', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('Token test-access-token', $request->getHeaderLine('Authorization'));

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('all', $body['status']);
            $this->assertSame('EN', $body['language']);
            $this->assertSame(['RR123456789TH'], $body['barcode']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/thailand-post/track.json'));
        };

        $adapter = new ThailandPost(
            new Config(['thailand-post' => ['app_token' => 'test-app-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('RR123456789TH');

        $this->assertSame('thailand-post', $tracking->carrierCode);
        $this->assertSame('RR123456789TH', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('รับฝาก', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('ส่งถึง', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('ส่งถึง', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                json_encode([
                    'status' => true,
                    'message' => 'ok',
                    'track' => ['RR123456789TH' => [
                        [
                            'status' => 'ส่งถึง',
                            'status_description' => 'ส่งถึง',
                            'status_date' => '2026-08-15 10:00:00',
                            'location' => 'Chiang Mai',
                        ],
                        [
                            'status' => 'รับฝาก',
                            'status_description' => 'รับฝาก',
                            'status_date' => '2026-08-12 09:00:00',
                            'location' => 'Bangkok',
                        ],
                    ]],
                ], JSON_UNESCAPED_UNICODE));
        };

        $adapter = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http);

        $tracking = $adapter->queryTrack('RR123456789TH');

        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/thailand-post/empty.json'));
        };

        $adapter = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789TH');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/thailand-post/error.json'));
        };

        $adapter = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http);

        try {
            $adapter->queryTrack('RR123456789TH');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[THAILAND-POST] Tracking number is invalid', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        // token 端点 401
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http);

        try {
            $adapter->queryTrack('RR123456789TH');
            $this->fail('expected AuthException for token 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[THAILAND-POST 401] OAuth token 获取失败', $e->getMessage());
        }

        // track 端点 401
        $http2 = new FakeHttpClient();
        $http2->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            return new Response(401, [], 'unauthorized');
        };

        $adapter2 = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http2);

        try {
            $adapter2->queryTrack('RR123456789TH');
            $this->fail('expected AuthException for track 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[THAILAND-POST 401] 认证失败', $e->getMessage());
        }

        // token 响应解析失败
        $http3 = new FakeHttpClient();
        $http3->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['status' => false, 'message' => 'invalid token'], JSON_UNESCAPED_UNICODE));

        $adapter3 = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http3);

        try {
            $adapter3->queryTrack('RR123456789TH');
            $this->fail('expected AuthException for token parse failure');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[THAILAND-POST] OAuth token 响应解析失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), 'authenticate/token')) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    json_encode(['status' => true, 'response' => ['token' => 'test-access-token']], JSON_UNESCAPED_UNICODE));
            }

            return new Response(200, ['Content-Type' => 'application/json'], '"boom"');
        };

        $adapter = new ThailandPost(new Config(['thailand-post' => ['app_token' => 'test-app-token']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789TH');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new ThailandPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('thailand-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789TH'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('thailand-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('thailand-post subscribe 待实现', $e->getMessage());
        }
    }
}
