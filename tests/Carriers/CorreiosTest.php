<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Correios;
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

final class CorreiosTest extends TestCase
{
    private function adapter(FakeHttpClient $http): Correios
    {
        return new Correios(
            new Config(['correios' => ['user' => 'user-1', 'password' => 'pass-1']]),
            $http,
        );
    }

    public function testQueryTrackFetchesTokenThenQueriesRastro(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === 'https://api.correios.com.br/token/v1/autentica') {
                $this->assertSame(
                    'Basic ' . base64_encode('user-1:pass-1'),
                    $request->getHeaderLine('Authorization'),
                );
                $this->assertSame('POST', $request->getMethod());

                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"token":"tok-abc","expiraEm":"2026-12-31T23:59:59-03:00"}');
            }

            $this->assertSame('Bearer tok-abc', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('/srorastro/v1/objetos/DG049186226BR', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/correios/track.json'));
        };

        $tracking = $this->adapter($http)->queryTrack('DG049186226BR');

        $this->assertSame('correios', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Objeto postado', $tracking->events[0]->description);
        $this->assertSame('SAO PAULO - SP', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Objeto em rota de entrega', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Objeto entregue ao destinatário', $tracking->latestDescription);
        $this->assertSame('XAPURI - AC', $tracking->events[3]->location);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-13 17:03:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('BDE', $tracking->rawStatus);
    }

    public function testQueryTrackReusesCachedToken(): void
    {
        $http = new FakeHttpClient();
        $tokenRequests = 0;
        $http->handler = function (Request $request) use (&$tokenRequests) {
            if ((string) $request->getUri() === 'https://api.correios.com.br/token/v1/autentica') {
                $tokenRequests++;

                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"token":"tok-abc","expiraEm":"2026-12-31T23:59:59-03:00"}');
            }

            $this->assertSame('Bearer tok-abc', $request->getHeaderLine('Authorization'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/correios/track.json'));
        };

        $adapter = $this->adapter($http);
        $adapter->queryTrack('DG049186226BR');
        $adapter->queryTrack('DG049186226BR');

        $this->assertSame(1, $tokenRequests);
    }

    public function testQueryTrackThrowsOnTokenAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[CORREIOS 401] 认证失败');

        $this->adapter($http)->queryTrack('DG049186226BR');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === 'https://api.correios.com.br/token/v1/autentica') {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"token":"tok-abc","expiraEm":"2026-12-31T23:59:59-03:00"}');
            }

            return new Response(503, ['Content-Type' => 'text/plain'], 'Service Unavailable');
        };

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CORREIOS 503] 接口错误');

        $this->adapter($http)->queryTrack('DG049186226BR');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === 'https://api.correios.com.br/token/v1/autentica') {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"token":"tok-abc","expiraEm":"2026-12-31T23:59:59-03:00"}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/correios/empty.json'));
        };

        $this->expectException(TrackingNotFoundException::class);

        $this->adapter($http)->queryTrack('DG049186226BR');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === 'https://api.correios.com.br/token/v1/autentica') {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"token":"tok-abc","expiraEm":"2026-12-31T23:59:59-03:00"}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/correios/error.json'));
        };

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('Objeto não encontrado');

        $this->adapter($http)->queryTrack('DG049186226BR');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->adapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('correios createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('DG049186226BR'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('correios createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('correios subscribe 待实现', $e->getMessage());
        }
    }
}
