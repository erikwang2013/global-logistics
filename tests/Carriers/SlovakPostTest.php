<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\SlovakPost;
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

final class SlovakPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://api.posta.sk/tracking?q=RA123456785SK&l=en',
                (string) $request->getUri()
            );
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/slovak-post/track.json'));
        };

        $adapter = new SlovakPost(new Config(['slovak-post' => ['locale' => 'en']]), $http);

        $tracking = $adapter->queryTrack('RA123456785SK');

        $this->assertSame('slovak-post', $tracking->carrierCode);
        $this->assertSame('RA123456785SK', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Item posted at post office Bratislava 12', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[2]->status);
        $this->assertSame('Bratislava 32 Bratislava', $tracking->events[3]->location);
        $this->assertSame('Item delivered to the Addressee at the post office Bratislava 32', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('delivered', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'status' => 'ok',
                'results' => [
                    [
                        'status' => 'ok',
                        'number' => 'RA123456785SK',
                        'events' => [
                            ['detailDescription' => 'Item delivered', 'stateCode' => 'delivered', 'localDate' => '2026-08-14T12:10:00'],
                            ['detailDescription' => 'Item posted at post office', 'stateCode' => 'received', 'localDate' => '2026-08-11T08:00:00'],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new SlovakPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RA123456785SK');

        $this->assertSame('2026-08-11 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/slovak-post/empty.json'));

        $adapter = new SlovakPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RA123456785SK');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/slovak-post/error.json'));

        $adapter = new SlovakPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456785SK');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[SLOVAK-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new SlovakPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456785SK');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[SLOVAK-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new SlovakPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RA123456785SK');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new SlovakPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('slovak-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA123456785SK'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('slovak-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('slovak-post subscribe 待实现', $e->getMessage());
        }
    }
}
