<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\BulgariaPost;
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

final class BulgariaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://api.bgpost.bg/api/v1/tracking',
                (string) $request->getUri()
            );
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('test-key', $request->getHeaderLine('portalSessionGUID'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('RR123456789BG', $body['barcode']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/bulgaria-post/track.json'));
        };

        $adapter = new BulgariaPost(new Config(['bulgaria-post' => ['session_guid' => 'test-key']]), $http);

        $tracking = $adapter->queryTrack('RR123456789BG');

        $this->assertSame('bulgaria-post', $tracking->carrierCode);
        $this->assertSame('RR123456789BG', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Прието в сортировъчен център', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Пловдив', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Доставено', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Доставено', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'data' => [
                    'status' => 'Доставено',
                    'events' => [
                        [
                            'date' => '2026-08-14T12:10:00+03:00',
                            'event' => 'Доставено',
                            'place' => 'София',
                        ],
                        [
                            'date' => '2026-08-11T08:00:00+03:00',
                            'event' => 'Прието в сортировъчен център',
                            'place' => 'Пловдив',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new BulgariaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789BG');

        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/bulgaria-post/empty.json'));

        $adapter = new BulgariaPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789BG');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/bulgaria-post/error.json'));

        $adapter = new BulgariaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789BG');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[BULGARIA-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new BulgariaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789BG');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[BULGARIA-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new BulgariaPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789BG');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new BulgariaPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bulgaria-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789BG'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bulgaria-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('bulgaria-post subscribe 待实现', $e->getMessage());
        }
    }
}
