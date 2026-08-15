<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\MagyarPosta;
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

final class MagyarPostaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://core.api.posta.hu/v2/nvomkovetes/registered', (string) $request->getUri());
            $this->assertSame('Bearer tok123', $request->getHeaderLine('Authorization'));
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('RR123456789HU', (string) $request->getBody());
            $this->assertStringContainsString('"lds"', (string) $request->getBody());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/magyar-posta/track.json'));
        };

        $adapter = new MagyarPosta(new Config(['magyar-posta' => ['access_token' => 'tok123']]), $http);

        $tracking = $adapter->queryTrack('RR123456789HU');

        $this->assertSame('magyar-posta', $tracking->carrierCode);
        $this->assertSame('RR123456789HU', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('A küldeményt a feladótól átvettük', $tracking->events[0]->description);
        $this->assertSame('2026-08-10 08:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Budapest 62', $tracking->events[0]->location);
        $this->assertSame('Sikeresen kézbesítve', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Sikeresen kézbesítve', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'result' => [
                    'item' => [
                        'events' => [
                            [
                                'event_timestamp' => '2026-08-14T12:10:00+02:00',
                                'event_type' => 'Sikeresen kézbesítve',
                                'post_name' => 'Budapest 62',
                            ],
                            [
                                'event_timestamp' => '2026-08-12T11:30:00+02:00',
                                'event_type' => 'A küldemény szállítás alatt',
                                'post_name' => 'Budapest',
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new MagyarPosta(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789HU');

        $this->assertSame('2026-08-12 11:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/magyar-posta/empty.json'));

        $adapter = new MagyarPosta(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789HU');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/magyar-posta/error.json'));

        $adapter = new MagyarPosta(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789HU');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[MAGYAR-POSTA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new MagyarPosta(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789HU');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[MAGYAR-POSTA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new MagyarPosta(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789HU');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new MagyarPosta(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('magyar-posta createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789HU'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('magyar-posta createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('magyar-posta subscribe 待实现', $e->getMessage());
        }
    }
}
