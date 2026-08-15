<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\AustrianPost;
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

final class AustrianPostTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://customerservices.post.at/api/v1/GetParcelDetail?format=json', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('Basic ' . base64_encode('test-user:test-pass'), $request->getHeaderLine('Authorization'));

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('RR123456789AT', $body['TrackingNumber']);
            $this->assertSame('test-user', $body['UserName']);
            $this->assertSame('test-pass', $body['Password']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/austrian-post/track.json'));
        };

        $adapter = new AustrianPost(
            new Config(['austrian-post' => [
                'user_name' => 'test-user',
                'password' => 'test-pass',
            ]]),
            $http,
        );

        $tracking = $adapter->queryTrack('RR123456789AT');

        $this->assertSame('austrian-post', $tracking->carrierCode);
        $this->assertSame('RR123456789AT', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Sendung eingeliefert', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Zugestellt an Empfänger', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'FoundTracking' => true,
                'RawData' => ['events' => [
                    [
                        'status' => 'Delivered',
                        'description' => 'Zugestellt an Empfänger',
                        'location' => 'Wien',
                        'eventDate' => '2026-08-15T10:00:00',
                    ],
                    [
                        'status' => 'Posted',
                        'description' => 'Sendung eingeliefert',
                        'location' => 'Wien',
                        'eventDate' => '2026-08-12T09:00:00',
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new AustrianPost(new Config(['austrian-post' => []]), $http);

        $tracking = $adapter->queryTrack('RR123456789AT');

        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/austrian-post/empty.json'));

        $adapter = new AustrianPost(new Config(['austrian-post' => []]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789AT');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/austrian-post/error.json'));

        $adapter = new AustrianPost(new Config(['austrian-post' => []]), $http);

        try {
            $adapter->queryTrack('RR123456789AT');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[AUSTRIAN-POST] Invalid tracking number', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new AustrianPost(new Config(['austrian-post' => []]), $http);

        try {
            $adapter->queryTrack('RR123456789AT');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[AUSTRIAN-POST 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new AustrianPost(new Config(['austrian-post' => []]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789AT');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new AustrianPost(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('austrian-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789AT'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('austrian-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('austrian-post subscribe 待实现', $e->getMessage());
        }
    }
}
