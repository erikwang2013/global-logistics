<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Yanwen;
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

final class YanwenTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Yanwen
    {
        return new Yanwen(
            new Config(['yanwen' => ['customer_code' => 'test-yanwen-customer-code', 'api_secret' => 'test-yanwen-api-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://open.yw56.com.cn/api/order',
                (string) $request->getUri(),
            );
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame(
                'Basic ' . base64_encode('test-yanwen-customer-code&test-yanwen-api-secret'),
                $request->getHeaderLine('Authorization'),
            );
            $body = (string) $request->getBody();
            $this->assertStringContainsString('get-tracking', $body);
            $this->assertStringContainsString('UA123456789YP', $body);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yanwen/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('UA123456789YP');

        $this->assertSame('yanwen', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('已揽收，快件到达深圳分拨中心', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('深圳市', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-13T16:20:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('D', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'success' => true,
                'data' => [
                    'trackingNumber' => 'UA123456789YP',
                    'events' => [
                        ['time' => '2026-08-13 16:20:00', 'location' => 'New York', 'description' => 'Delivered', 'statusCode' => 'D'],
                        ['time' => '2026-08-11 09:00:00', 'location' => '深圳', 'description' => '已揽收', 'statusCode' => 'P'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('UA123456789YP');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('已揽收', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yanwen/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('UA123456789YP');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yanwen/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YANWEN 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('UA123456789YP');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YANWEN 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('UA123456789YP');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YANWEN] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('UA123456789YP');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('yanwen createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('UA123456789YP'));
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
