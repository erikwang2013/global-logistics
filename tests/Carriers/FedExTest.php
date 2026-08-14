<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\FedEx;
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

final class FedExTest extends TestCase
{
    private function makeHttp(): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}');
            }

            $this->assertSame('Bearer tok-fedex', $request->getHeaderLine('Authorization'));
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

            $payload = json_decode((string) $request->getBody(), true);
            $this->assertSame('FEDEX1234567890', $payload['trackingNumberInfo']['trackingNumber'] ?? null);
            $this->assertSame(true, $payload['includeDetailedScans'] ?? null);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/fedex/track.json'));
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): FedEx
    {
        return new FedEx(
            new Config(['fedex' => ['client_id' => 'test-fedex-client-id', 'client_secret' => 'test-fedex-client-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $tracking = $this->makeAdapter($this->makeHttp())->queryTrack('FEDEX1234567890');

        $this->assertSame('fedex', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Shanghai', $tracking->events[0]->location);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame('2026-08-14T09:15:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('DELIVERED', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsWhenNoResult(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/fedex/empty.json'));
        };

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"message":"Unauthorized"}]}');
        };

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[FEDEX 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'], '"boom"');
        };

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[FEDEX] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('FEDEX1234567890');
    }

    public function testUnmappedStateFallsBackToEventStatus(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}');
            }

            $body = json_encode([
                'output' => [
                    'completeTrackResults' => [
                        [
                            'trackResults' => [
                                [
                                    'statusByTrack' => ['state' => 'CUSTOMS_HOLD'],
                                    'scanEvents' => [
                                        [
                                            'date' => '2026-08-15',
                                            'time' => '12:00:00',
                                            'scanLocation' => ['city' => 'Memphis'],
                                            'scanDescription' => 'Delivered',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('FEDEX1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertSame('CUSTOMS_HOLD', $tracking->rawStatus);
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp());

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('FEDEX createOrder 待实现');
        $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('FEDEX createLabel 待实现');
        $adapter->createLabel(new Order('FEDEX1234567890'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('FEDEX subscribe 待实现');
        $adapter->subscribe('https://example.com/hook');
    }
}
