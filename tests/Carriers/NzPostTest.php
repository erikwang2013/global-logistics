<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\NzPost;
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

final class NzPostTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            $query = $request->getUri()->getQuery();
            $this->assertStringContainsString('license_key=test-license', $query);
            $this->assertStringContainsString('user_ip_address=127.0.0.1', $query);
            $this->assertStringContainsString('tracking_code=RR123456789NZ', $query);
            $this->assertStringContainsString('format=json', $query);

            return new Response(200, ['Content-Type' => 'application/json'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): NzPost
    {
        return new NzPost(
            new Config(['nz-post' => ['license_key' => 'test-license']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/nz-post/track.json')));

        $tracking = $adapter->queryTrack('RR123456789NZ');

        $this->assertSame('nz-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Delivery Complete', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivery Complete', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = json_encode([
            'RR123456789NZ' => [
                'short_description' => 'Delivery Complete',
                'events' => [
                    ['flag' => 'F', 'description' => 'Delivery Complete', 'datetime' => '2026-08-14T10:00:00+12:00'],
                    ['flag' => 'A', 'description' => 'Picked up', 'datetime' => '2026-08-08T09:00:00+12:00'],
                ],
                'source' => 'nz_post',
            ],
        ], JSON_THROW_ON_ERROR);

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('RR123456789NZ');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame('Delivery Complete', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{"message":"Unauthorised"}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[NZ-POST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RR123456789NZ');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/nz-post/error.json')));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[NZ-POST] Unauthorised - license key expired or similar');

        $adapter->queryTrack('RR123456789NZ');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/nz-post/empty.json')));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('RR123456789NZ');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not json at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[NZ-POST] 响应解析失败');

        $adapter->queryTrack('RR123456789NZ');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('NZ-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789NZ'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('NZ-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('NZ-POST subscribe 待实现', $e->getMessage());
        }
    }
}
