<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\AustraliaPost;
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

final class AustraliaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('test-api-key', $request->getHeaderLine('AUSPOST-AUTH-APIKEY'));
            $this->assertStringContainsString('/track/AA123456789AU', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/australia-post/track.json'));
        };

        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('AA123456789AU');

        $this->assertSame('australia-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Received', $tracking->events[0]->description);
        $this->assertSame('Sydney', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('D', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{"error":{"code":"UNAUTHORIZED"}}');

        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[AUSTRALIA-POST 401] 认证失败');

        $adapter->queryTrack('AA123456789AU');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/json'], '{"error":{"code":"INTERNAL"}}');

        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[AUSTRALIA-POST 500] 接口错误');

        $adapter->queryTrack('AA123456789AU');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/australia-post/empty.json'));

        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('AA123456789AU');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[AUSTRALIA-POST] 响应解析失败');

        $adapter->queryTrack('AA123456789AU');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new AustraliaPost(
            new Config(['australia-post' => ['api_key' => 'test-api-key']]),
            new FakeHttpClient(),
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('australia-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('AA123456789AU'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('australia-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('australia-post subscribe 待实现', $e->getMessage());
        }
    }
}
