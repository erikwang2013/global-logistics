<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\CanadaPost;
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

final class CanadaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame(
                'Basic ' . base64_encode('cust-123:key-abc'),
                $request->getHeaderLine('Authorization'),
            );
            $this->assertSame('application/vnd.cpc.track+xml', $request->getHeaderLine('Accept'));
            $this->assertStringContainsString('/vis/track/pin/PA123456789CA/details', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/vnd.cpc.track+xml'],
                file_get_contents(__DIR__ . '/../fixtures/canada-post/track.xml'));
        };

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $tracking = $adapter->queryTrack('PA123456789CA');

        $this->assertSame('canada-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('In transit', $tracking->events[0]->description);
        $this->assertSame('Vancouver', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('London', $tracking->events[2]->location);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('M0', $tracking->rawStatus);
        $this->assertCount(3, $tracking->raw['significant_events']);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[CANADA-POST 401] 认证失败');

        $adapter->queryTrack('PA123456789CA');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(503, ['Content-Type' => 'text/plain'], 'Service Unavailable');

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CANADA-POST 503] 接口错误');

        $adapter->queryTrack('PA123456789CA');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/vnd.cpc.track+xml'],
            file_get_contents(__DIR__ . '/../fixtures/canada-post/empty.xml'));

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('PA123456789CA');
    }

    public function testQueryTrackThrowsOnErrorElement(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/vnd.cpc.track+xml'],
            file_get_contents(__DIR__ . '/../fixtures/canada-post/error.xml'));

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('PA123456789CA');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/vnd.cpc.track+xml'], 'not xml at all');

        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CANADA-POST] 响应解析失败');

        $adapter->queryTrack('PA123456789CA');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new CanadaPost(
            new Config(['canada-post' => ['customer_number' => 'cust-123', 'api_key' => 'key-abc']]),
            new FakeHttpClient(),
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('canada-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('PA123456789CA'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('canada-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('canada-post subscribe 待实现', $e->getMessage());
        }
    }
}
