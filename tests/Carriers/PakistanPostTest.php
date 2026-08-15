<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\PakistanPost;
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

final class PakistanPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://ep.gov.pk/track.asp', (string) $request->getUri());
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            return new Response(200, ['Content-Type' => 'text/html'],
                file_get_contents(__DIR__ . '/../fixtures/pakistan-post/track.json'));
        };

        $adapter = new PakistanPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RA123456785PK');

        $this->assertSame('pakistan-post', $tracking->carrierCode);
        $this->assertSame('RA123456785PK', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Item in transit', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('ISLAMABAD', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Item delivered to recipient', $tracking->latestDescription);
        $this->assertSame('2026-08-14 00:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/html'],
            '<table><tr><td>Date</td><td>Location</td><td>Status</td></tr>'
            . '<tr><td>14/08/2026</td><td>KARACHI</td><td>Item delivered to recipient</td></tr>'
            . '<tr><td>11/08/2026</td><td>ISLAMABAD</td><td>Item in transit</td></tr></table>');

        $adapter = new PakistanPost(new Config([]), $http);

        $tracking = $adapter->queryTrack('RA123456785PK');

        $this->assertSame('2026-08-11 00:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/html'],
            file_get_contents(__DIR__ . '/../fixtures/pakistan-post/empty.json'));

        $adapter = new PakistanPost(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RA123456785PK');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'text/html'],
            file_get_contents(__DIR__ . '/../fixtures/pakistan-post/error.json'));

        $adapter = new PakistanPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456785PK');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[PAKISTAN-POST 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], 'forbidden');

        $adapter = new PakistanPost(new Config([]), $http);

        try {
            $adapter->queryTrack('RA123456785PK');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[PAKISTAN-POST 403] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/html'], '"boom"');

        $adapter = new PakistanPost(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RA123456785PK');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new PakistanPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pakistan-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA123456785PK'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pakistan-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('pakistan-post subscribe 待实现', $e->getMessage());
        }
    }
}
