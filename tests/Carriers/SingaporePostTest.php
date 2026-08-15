<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\SingaporePost;
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

final class SingaporePostTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            $this->assertSame('test-api-key', $request->getHeaderLine('api-key'));
            $this->assertStringContainsString('"trackingNumber"', (string) $request->getBody());
            $this->assertStringContainsString('RR123456789SG', (string) $request->getBody());

            return new Response(200, ['Content-Type' => 'application/json'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): SingaporePost
    {
        return new SingaporePost(
            new Config(['singapore-post' => ['api_key' => 'test-api-key']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/singapore-post/track.json')));

        $tracking = $adapter->queryTrack('RR123456789SG');

        $this->assertSame('singapore-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Item Collected', $tracking->events[0]->description);
        $this->assertSame('Singapore', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Item Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T15:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = json_encode([
            'data' => [
                'trackingInfo' => [
                    [
                        'trackingNumber' => 'RR123456789SG',
                        'status' => 'Delivered',
                        'trackingEvents' => [
                            ['description' => 'Item Delivered', 'dateTime' => '2026-08-14T15:00:00+08:00'],
                            ['description' => 'Item Collected', 'dateTime' => '2026-08-08T09:00:00+08:00'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('RR123456789SG');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Item Collected', $tracking->events[0]->description);
        $this->assertSame('Item Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"message":"Unauthorized"}]}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[SINGAPORE-POST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RR123456789SG');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/singapore-post/error.json')));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SINGAPORE-POST] Invalid tracking number');

        $adapter->queryTrack('RR123456789SG');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/singapore-post/empty.json')));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('RR123456789SG');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not json at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SINGAPORE-POST] 响应解析失败');

        $adapter->queryTrack('RR123456789SG');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SINGAPORE-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789SG'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SINGAPORE-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SINGAPORE-POST subscribe 待实现', $e->getMessage());
        }
    }
}
