<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Correos;
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

final class CorreosTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Correos
    {
        return new Correos(
            new Config(['correos' => ['client_id' => 'test-client-id', 'client_secret' => 'test-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://api1.correos.es/trackpub/api/v1/search/RR123456789ES',
                (string) $request->getUri(),
            );
            $this->assertSame('test-client-id', $request->getHeaderLine('client_id'));
            $this->assertSame('test-secret', $request->getHeaderLine('client_secret'));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/correos/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('RR123456789ES');

        $this->assertSame('correos', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Admitido en oficina de cambio', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Madrid', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Entregado', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Entregado', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T17:45:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('DLVD', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'shippingCode' => 'RR123456789ES',
                'events' => [
                    ['code' => 'DLVD', 'description' => 'Entregado', 'date' => '2026-08-14T17:45:00+02:00', 'location' => 'Barcelona'],
                    ['code' => 'ADMD', 'description' => 'Admitido en oficina de cambio', 'date' => '2026-08-12T09:00:00+02:00', 'location' => 'Madrid'],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('RR123456789ES');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Admitido en oficina de cambio', $tracking->events[0]->description);
        $this->assertSame('Entregado', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/correos/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('RR123456789ES');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/correos/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CORREOS 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('RR123456789ES');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[CORREOS 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RR123456789ES');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CORREOS] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('RR123456789ES');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('correos createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789ES'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }
}
