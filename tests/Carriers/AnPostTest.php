<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\AnPost;
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

final class AnPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://apim-anpost-apwebapis.anpost.com/ttservice-public-apweb/GetEvents',
                (string) $request->getUri()
            );
            $this->assertSame('test-key', $request->getHeaderLine('Ocp-Apim-Subscription-Key'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('RR123456789IE', $body['getEvents']['barcodeItem']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/an-post/track.json'));
        };

        $adapter = new AnPost(new Config(['an-post' => ['subscription_key' => 'test-key']]), $http);

        $tracking = $adapter->queryTrack('RR123456789IE');

        $this->assertSame('an-post', $tracking->carrierCode);
        $this->assertSame('RR123456789IE', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Your item has been sorted in', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Dublin Mail Centre', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Your item has been delivered', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('14', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'getEventsResponse' => [
                    'GetEventsResult' => [
                        [
                            'activity' => 'Your item has been delivered',
                            'date' => '2026-08-14T12:10:00',
                            'location' => 'Dublin 1 Delivery Office',
                            'traceCode' => 14,
                        ],
                        [
                            'activity' => 'Your item has been sorted in',
                            'date' => '2026-08-12T10:00:00',
                            'location' => 'Dublin Mail Centre',
                            'traceCode' => 1,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new AnPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789IE');

        $this->assertSame('2026-08-12 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/an-post/empty.json'));

        $adapter = new AnPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789IE');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/an-post/error.json'));

        $adapter = new AnPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789IE');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[AN-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new AnPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789IE');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[AN-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new AnPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789IE');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new AnPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('an-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789IE'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('an-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('an-post subscribe 待实现', $e->getMessage());
        }
    }
}
