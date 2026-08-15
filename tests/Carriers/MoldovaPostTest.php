<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\MoldovaPost;
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

final class MoldovaPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://www.posta.md/en/track-trace/mailing/RR123456789MD',
                (string) $request->getUri()
            );
            $this->assertSame('text/html, application/json', $request->getHeaderLine('Accept'));

            return new Response(200, ['Content-Type' => 'text/html'],
                file_get_contents(__DIR__ . '/../fixtures/moldova-post/track.json'));
        };

        $adapter = new MoldovaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789MD');

        $this->assertSame('moldova-post', $tracking->carrierCode);
        $this->assertSame('RR123456789MD', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Preluare expediere', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Chișinău MC', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Livrat', $tracking->latestDescription);
        $this->assertSame('2026-08-15 00:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/html'],
            '<table><tbody>'
            . '<tr><td>15/08/2026</td><td>Bălți</td><td>Livrat</td></tr>'
            . '<tr><td>11/08/2026</td><td>Chișinău MC</td><td>Preluare expediere</td></tr>'
            . '</tbody></table>');

        $adapter = new MoldovaPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789MD');

        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/html'],
            file_get_contents(__DIR__ . '/../fixtures/moldova-post/empty.json'));

        $adapter = new MoldovaPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789MD');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/moldova-post/error.json'));

        $adapter = new MoldovaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MD');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[MOLDOVA-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], 'forbidden');

        $adapter = new MoldovaPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789MD');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[MOLDOVA-POST 403] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new MoldovaPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789MD');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new MoldovaPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('moldova-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789MD'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('moldova-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('moldova-post subscribe 待实现', $e->getMessage());
        }
    }
}
