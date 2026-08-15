<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\MacedoniaPost;
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

final class MacedoniaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://www.posta.com.mk/api/api.php/shipment?code=RR123456789MK',
                (string) $request->getUri()
            );
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/macedonia-post/track.json'));
        };

        $adapter = new MacedoniaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789MK');

        $this->assertSame('macedonia-post', $tracking->carrierCode);
        $this->assertSame('RR123456789MK', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Примена во пошта', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Скопје МЦ', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Испорачана', $tracking->latestDescription);
        $this->assertSame('2026-08-14 00:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Испорачана', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'success' => true,
                'tracking_code' => 'RR123456789MK',
                'data' => [
                    ['Од' => 'Скопје', 'До' => 'ЦАРИНА - Скопје', 'Датум' => '2026-08-14', 'Забелешка' => 'Испорачана'],
                    ['Од' => 'Кина', 'До' => 'Скопје МЦ', 'Датум' => '2026-08-11', 'Забелешка' => 'Примена во пошта'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new MacedoniaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789MK');

        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/macedonia-post/empty.json'));

        $adapter = new MacedoniaPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789MK');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/macedonia-post/error.json'));

        $adapter = new MacedoniaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MK');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[MACEDONIA-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], 'forbidden');

        $adapter = new MacedoniaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MK');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[MACEDONIA-POST 403] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new MacedoniaPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789MK');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new MacedoniaPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('macedonia-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789MK'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('macedonia-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('macedonia-post subscribe 待实现', $e->getMessage());
        }
    }
}
