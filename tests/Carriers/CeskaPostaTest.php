<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\CeskaPosta;
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

final class CeskaPostaTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('https://b2c.cpost.cz/services/ParcelHistory/getDataAsJson?idParcel=RR834695895CZ', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ceska-posta/track.json'));
        };

        $adapter = new CeskaPosta(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR834695895CZ');

        $this->assertSame('ceska-posta', $tracking->carrierCode);
        $this->assertSame('RR834695895CZ', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Podání zásilky', $tracking->events[0]->description);
        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Praha 1 110 00', $tracking->events[0]->location);
        $this->assertSame('Doručení zásilky', $tracking->latestDescription);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('300', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                [
                    'dateTime' => '2026-08-14T10:45:00.000+02:00',
                    'code' => '300',
                    'text' => 'Doručení zásilky',
                    'postName' => 'Praha 30',
                ],
                [
                    'dateTime' => '2026-08-10T09:12:00.000+02:00',
                    'code' => '100',
                    'text' => 'Podání zásilky',
                    'postName' => 'Praha 1',
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new CeskaPosta(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR834695895CZ');

        $this->assertSame('2026-08-10 09:12:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame('2026-08-14 10:45:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ceska-posta/empty.json'));

        $adapter = new CeskaPosta(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR834695895CZ');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ceska-posta/error.json'));

        $adapter = new CeskaPosta(new Config([]), $http);

        try {
            $adapter->queryTrack('RR834695895CZ');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CESKA-POSTA 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new CeskaPosta(new Config([]), $http);

        try {
            $adapter->queryTrack('RR834695895CZ');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CESKA-POSTA 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new CeskaPosta(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR834695895CZ');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new CeskaPosta(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ceska-posta createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR834695895CZ'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ceska-posta createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ceska-posta subscribe 待实现', $e->getMessage());
        }
    }
}
