<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Ups;
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

final class UpsTest extends TestCase
{
    private function makeHttp(): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                // 钉住 basicAuth:true 接线：凭据走 Basic header，body 仅 grant_type
                $this->assertStringStartsWith('Basic ', $request->getHeaderLine('Authorization'));
                $this->assertStringContainsString('grant_type=client_credentials', (string) $request->getBody());

                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}');
            }

            $this->assertSame('Bearer tok-ups', $request->getHeaderLine('Authorization'));
            $this->assertSame('GET', $request->getMethod());
            $this->assertNotSame('', $request->getHeaderLine('transId'));
            $this->assertSame('global-logistics', $request->getHeaderLine('transactionSrc'));
            $this->assertStringContainsString('/track/v1/details/1Z9999999999999999', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ups/track.json'));
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): Ups
    {
        return new Ups(
            new Config(['ups' => ['client_id' => 'test-ups-client-id', 'client_secret' => 'test-ups-client-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $tracking = $this->makeAdapter($this->makeHttp())->queryTrack('1Z9999999999999999');

        $this->assertSame('ups', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Shenzhen CN', $tracking->events[0]->location);
        $this->assertSame('In Transit', $tracking->events[0]->description);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertSame('Shanghai CN', $tracking->events[1]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[1]->occurredAt);
        $this->assertSame('2026-08-15T18:30:00', $tracking->events[1]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-15T18:30:00', $tracking->deliveredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('D', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsWhenNoPackage(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ups/empty.json'));
        };

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"response":{"errors":[{"code":"250002"}]}}');
        };

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[UPS 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}');
            }

            return new Response(200, ['Content-Type' => 'application/json'], '"boom"');
        };

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[UPS] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('1Z9999999999999999');
    }

    public function testMapStatusVariants(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}');
            }

            $body = json_encode([
                'trackResponse' => [
                    'shipment' => [
                        [
                            'package' => [
                                [
                                    'activity' => [
                                        ['date' => '20260810', 'time' => '010000', 'status' => ['type' => 'I', 'description' => 'In Transit']],
                                        ['date' => '20260811', 'time' => '020000', 'status' => ['type' => 'O', 'description' => 'Out For Delivery']],
                                        ['date' => '20260812', 'time' => '030000', 'status' => ['type' => 'X', 'description' => 'Exception']],
                                        ['date' => '20260813', 'time' => '040000', 'status' => ['type' => 'R', 'description' => 'Returned']],
                                        ['date' => '20260814', 'time' => '050000', 'status' => ['type' => 'M', 'description' => 'Pending']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('1Z9999999999999999');

        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::RETURNED, $tracking->events[3]->status);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[4]->status);
        $this->assertSame(TrackStatus::PENDING, $tracking->status);
        $this->assertSame('M', $tracking->rawStatus);
        $this->assertNull($tracking->deliveredAt);
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('1Z9999999999999999'));
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
