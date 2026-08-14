<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Sto;
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

final class StoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('773001234567890', $body['waybillNo'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/sto/track.json'));
        };

        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        $tracking = $adapter->queryTrack('773001234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sto/empty.json'));

        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('773001234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sto/error.json'));

        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[STO 4001] 认证失败');

        $adapter->queryTrack('773001234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('773001234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $http = new FakeHttpClient();
        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('SF1234567890'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }

    public function testExceptionKeywordWinsOverDelivered(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'status' => '1',
                'data' => [
                    'traces' => [
                        ['scanTime' => '2026-08-15 18:30:00', 'location' => '杭州市', 'scanDesc' => '签收异常-收件人拒收'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Sto(
            new Config(['sto' => ['customer_id' => 'test']]),
            $http,
        );

        $tracking = $adapter->queryTrack('773001234567890');

        $this->assertSame(TrackStatus::EXCEPTION, $tracking->status);
    }
}
