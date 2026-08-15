<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\IcelandPost;
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

final class IcelandPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://api.mobiz.posturinn.is/api/tracking/RR123456789IS',
                (string) $request->getUri()
            );
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('test-key', $request->getHeaderLine('X-Api-Key'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/iceland-post/track.json'));
        };

        $adapter = new IcelandPost(new Config(['iceland-post' => ['key' => 'test-key']]), $http);

        $tracking = $adapter->queryTrack('RR123456789IS');

        $this->assertSame('iceland-post', $tracking->carrierCode);
        $this->assertSame('RR123456789IS', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Í flutningi', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Akureyri', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Afhent', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Afhent', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'data' => [
                    'status' => 'Afhent',
                    'events' => [
                        [
                            'dateTime' => '2026-08-14T12:10:00+00:00',
                            'event' => 'Afhent',
                            'place' => 'Reykjavík',
                        ],
                        [
                            'dateTime' => '2026-08-11T08:00:00+00:00',
                            'event' => 'Í flutningi',
                            'place' => 'Akureyri',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new IcelandPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789IS');

        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/iceland-post/empty.json'));

        $adapter = new IcelandPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789IS');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/iceland-post/error.json'));

        $adapter = new IcelandPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789IS');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[ICELAND-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], 'forbidden');

        $adapter = new IcelandPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789IS');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[ICELAND-POST 403] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new IcelandPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789IS');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new IcelandPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('iceland-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789IS'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('iceland-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('iceland-post subscribe 待实现', $e->getMessage());
        }
    }
}
