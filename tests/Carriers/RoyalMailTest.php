<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\RoyalMail;
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

final class RoyalMailTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                $this->assertSame(
                    'Basic ' . base64_encode('test-client-id:test-client-secret'),
                    $request->getHeaderLine('Authorization'),
                );
                $this->assertStringContainsString('grant_type=client_credentials', (string) $request->getBody());

                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-royal-mail","expires_in":3600}');
            }

            $this->assertSame('Bearer tok-royal-mail', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('mailPieceId=XX000000000GB', $request->getUri()->getQuery());

            return new Response(200, ['Content-Type' => 'application/json'], $trackBody);
        };

        return $http;
    }

    public function testQueryTrackParses(): void
    {
        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/royal-mail/track.json')),
        );

        $tracking = $adapter->queryTrack('XX000000000GB');

        $this->assertSame('royal-mail', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(5, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Collected', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[3]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[4]->status);
        $this->assertSame('London', $tracking->events[4]->location);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('D', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = json_encode([
            'mailPieces' => [
                [
                    'mailPieceId' => 'XX000000000GB',
                    'events' => [
                        ['eventCode' => 'D', 'eventName' => 'Delivered', 'location' => ['locationName' => 'London'], 'timestamp' => '2026-08-14T10:00:00Z'],
                        ['eventCode' => 'C', 'eventName' => 'Collected', 'location' => ['locationName' => 'Leeds'], 'timestamp' => '2026-08-10T09:00:00Z'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $this->makeHttp($body),
        );

        $tracking = $adapter->queryTrack('XX000000000GB');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Collected', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-royal-mail","expires_in":3600}');
            }

            return new Response(401, ['Content-Type' => 'application/json'], '{"errors":[{"message":"Unauthorized"}]}');
        };

        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[ROYAL-MAIL 401] 认证失败');

        $adapter->queryTrack('XX000000000GB');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-royal-mail","expires_in":3600}');
            }

            return new Response(500, ['Content-Type' => 'application/json'], '{"errors":[{"message":"Internal"}]}');
        };

        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ROYAL-MAIL 500] 接口错误');

        $adapter->queryTrack('XX000000000GB');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/royal-mail/empty.json')),
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('XX000000000GB');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $this->makeHttp('not json at all'),
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ROYAL-MAIL] 响应解析失败');

        $adapter->queryTrack('XX000000000GB');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new RoyalMail(
            new Config(['royal-mail' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            new FakeHttpClient(),
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('royal-mail createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('XX000000000GB'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('royal-mail createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('royal-mail subscribe 待实现', $e->getMessage());
        }
    }
}
