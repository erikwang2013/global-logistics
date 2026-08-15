<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\PocztaPolska;
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

final class PocztaPolskaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://uss.poczta-polska.pl/uss/v2.0/tracking/checkmailex',
                (string) $request->getUri()
            );
            $this->assertSame('test-key', $request->getHeaderLine('api_key'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('RR123456789PL', $body['number']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/poczta-polska/track.json'));
        };

        $adapter = new PocztaPolska(new Config(['poczta-polska' => ['api_key' => 'test-key']]), $http);

        $tracking = $adapter->queryTrack('RR123456789PL');

        $this->assertSame('poczta-polska', $tracking->carrierCode);
        $this->assertSame('RR123456789PL', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Nadanie przesyłki', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 08:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('UP Warszawa 1', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Doręczono', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('P_D', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'mailInfo' => [
                    'events' => [
                        [
                            'code' => 'P_D',
                            'name' => 'Doręczono',
                            'time' => '2026-08-14T12:10:00',
                            'state' => ['name' => 'DORĘCZONA'],
                        ],
                        [
                            'code' => 'P_NAD',
                            'name' => 'Nadanie przesyłki',
                            'time' => '2026-08-11T08:30:00',
                            'state' => ['name' => 'NADANA'],
                        ],
                    ],
                ],
                'mailStatus' => 0,
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new PocztaPolska(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789PL');

        $this->assertSame('2026-08-11 08:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/poczta-polska/empty.json'));

        $adapter = new PocztaPolska(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789PL');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/poczta-polska/error.json'));

        $adapter = new PocztaPolska(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789PL');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[POCZTA-POLSKA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new PocztaPolska(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789PL');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[POCZTA-POLSKA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new PocztaPolska(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789PL');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new PocztaPolska(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('poczta-polska createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789PL'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('poczta-polska createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('poczta-polska subscribe 待实现', $e->getMessage());
        }
    }
}
