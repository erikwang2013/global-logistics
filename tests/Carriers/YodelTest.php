<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Yodel;
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

final class YodelTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            if (str_ends_with((string) $request->getUri(), '/oauth/token')) {
                $this->assertStringContainsString('grant_type=client_credentials', (string) $request->getBody());
                $this->assertStringContainsString('client_id=test-client-id', (string) $request->getBody());
                $this->assertStringContainsString('client_secret=test-client-secret', (string) $request->getBody());

                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-yodel","expires_in":3600}');
            }

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('Bearer tok-yodel', $request->getHeaderLine('Authorization'));
            $this->assertSame(
                'https://api.yodel.co.uk/tracking/v1.0/parcels/JD0001234567890123',
                (string) $request->getUri(),
            );

            return new Response(200, ['Content-Type' => 'application/json'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): Yodel
    {
        return new Yodel(
            new Config(['yodel' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(
            file_get_contents(__DIR__ . '/../fixtures/yodel/track.json'),
        ));

        $tracking = $adapter->queryTrack('JD0001234567890123');

        $this->assertSame('yodel', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Parcel collected', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Manchester', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Parcel delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Parcel delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-12T13:20:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('DL', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = json_encode([
            'parcels' => [
                [
                    'parcelNumber' => 'JD0001234567890123',
                    'trackingEvents' => [
                        ['eventCode' => 'DL', 'eventDateTime' => '2026-08-12T13:20:00Z', 'eventDescription' => 'Parcel delivered', 'eventLocation' => 'London'],
                        ['eventCode' => 'PC', 'eventDateTime' => '2026-08-10T08:00:00Z', 'eventDescription' => 'Parcel collected', 'eventLocation' => 'Manchester'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('JD0001234567890123');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Parcel collected', $tracking->events[0]->description);
        $this->assertSame('Parcel delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(
            file_get_contents(__DIR__ . '/../fixtures/yodel/empty.json'),
        ));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('JD0001234567890123');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-yodel","expires_in":3600}');
            }

            return new Response(404, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yodel/error.json'));
        };

        $adapter = $this->makeAdapter($http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YODEL 404] 接口错误');

        $adapter->queryTrack('JD0001234567890123');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_ends_with((string) $request->getUri(), '/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-yodel","expires_in":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"message":"Unauthorized"}]}');
        };

        $adapter = $this->makeAdapter($http);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YODEL 401] 认证失败');

        $adapter->queryTrack('JD0001234567890123');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not json at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YODEL] 响应解析失败');

        $adapter->queryTrack('JD0001234567890123');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('yodel createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('JD0001234567890123'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('yodel createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('yodel subscribe 待实现', $e->getMessage());
        }
    }
}
