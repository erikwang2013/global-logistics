<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\LaPoste;
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

final class LaPosteTest extends TestCase
{
    private function adapter(FakeHttpClient $http): LaPoste
    {
        return new LaPoste(
            new Config(['la-poste' => ['api_key' => 'okapi-123']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('okapi-123', $request->getHeaderLine('X-Okapi-Key'));
            $this->assertStringContainsString('/suivi/v2/idships/EY604176344FR', (string) $request->getUri());
            $this->assertStringContainsString('lang=fr_FR', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/la-poste/track.json'));
        };

        $tracking = $this->adapter($http)->queryTrack('EY604176344FR');

        $this->assertSame('la-poste', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Pris en charge par Colissimo', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('En cours de livraison', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Colis livré', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-13 14:05:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('DI1', $tracking->rawStatus);
        $this->assertSame('EY604176344FR', $tracking->raw['shipment']['idShip']);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[LA-POSTE 401] 认证失败');

        $this->adapter($http)->queryTrack('EY604176344FR');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(503, ['Content-Type' => 'text/plain'], 'Service Unavailable');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[LA-POSTE 503] 接口错误');

        $this->adapter($http)->queryTrack('EY604176344FR');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/la-poste/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->adapter($http)->queryTrack('EY604176344FR');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/la-poste/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[LA-POSTE 500] Erreur interne du serveur');

        $this->adapter($http)->queryTrack('EY604176344FR');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[LA-POSTE] 响应解析失败');

        $this->adapter($http)->queryTrack('EY604176344FR');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->adapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('la-poste createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('EY604176344FR'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('la-poste createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('la-poste subscribe 待实现', $e->getMessage());
        }
    }
}
