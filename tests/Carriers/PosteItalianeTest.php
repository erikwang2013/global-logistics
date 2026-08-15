<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\PosteItaliane;
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

final class PosteItalianeTest extends TestCase
{
    private function adapter(FakeHttpClient $http): PosteItaliane
    {
        return new PosteItaliane(new Config([]), $http);
    }

    public function testQueryTrackParsesAndReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('application/json;charset=UTF-8', $request->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('/DQ-REST/ricercamultipla', (string) $request->getUri());

            $body = json_decode((string) $request->getBody(), true);
            $this->assertIsArray($body);
            $this->assertSame('WEB', $body['tipoRichiedente'] ?? null);
            $this->assertSame(['RA123456789IT'], $body['listaCodici'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/poste-italiane/track.json'));
        };

        $tracking = $this->adapter($http)->queryTrack('RA123456789IT');

        $this->assertSame('poste-italiane', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        // fixture 为倒序（最新在前），须反转回升序
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('In transito', $tracking->events[0]->description);
        $this->assertSame('ROMA', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('In consegna', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Consegnato', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Consegnato', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[POSTE-ITALIANE 401] 认证失败');

        $this->adapter($http)->queryTrack('RA123456789IT');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(503, ['Content-Type' => 'text/plain'], 'Service Unavailable');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[POSTE-ITALIANE 503] 接口错误');

        $this->adapter($http)->queryTrack('RA123456789IT');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/poste-italiane/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->adapter($http)->queryTrack('RA123456789IT');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/poste-italiane/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('Errore generico nel recupero delle informazioni');

        $this->adapter($http)->queryTrack('RA123456789IT');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[POSTE-ITALIANE] 响应解析失败');

        $this->adapter($http)->queryTrack('RA123456789IT');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->adapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('poste-italiane createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA123456789IT'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('poste-italiane createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('poste-italiane subscribe 待实现', $e->getMessage());
        }
    }
}
