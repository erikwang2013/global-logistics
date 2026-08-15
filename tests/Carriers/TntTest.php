<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Tnt;
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

final class TntTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Tnt
    {
        return new Tnt(
            new Config(['tnt' => ['company_id' => 'test-tnt-company-id', 'password' => 'test-tnt-password']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://express.tnt.com/expressconnect/itrack',
                (string) $request->getUri(),
            );
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('test-tnt-company-id', $body);
            $this->assertStringContainsString('test-tnt-password', $body);
            $this->assertStringContainsString('consignmentNumber', $body);
            $this->assertStringContainsString('256867154', $body);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/tnt/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('256867154');

        $this->assertSame('tnt', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Shipment received at depot', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Arnhem', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-11T09:30:00', $tracking->events[0]->occurredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T17:45:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('DEL', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'consignments' => [
                    [
                        'conNumber' => '256867154',
                        'statusData' => ['statusCode' => 'DEL', 'statusDescription' => 'Delivered'],
                        'events' => [
                            ['statusCode' => 'DEL', 'statusDescription' => 'Delivered', 'localEventDate' => '20260814', 'localEventTime' => '1745', 'depot' => ['depotName' => 'London']],
                            ['statusCode' => 'SH', 'statusDescription' => 'Shipment received at depot', 'localEventDate' => '20260811', 'localEventTime' => '0930', 'depot' => ['depotName' => 'Arnhem']],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('256867154');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Shipment received at depot', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoConsignment(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/tnt/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('256867154');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/tnt/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[TNT 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('256867154');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[TNT 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('256867154');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[TNT] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('256867154');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('tnt createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('256867154'));
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
