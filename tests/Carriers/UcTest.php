<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Uc;
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

final class UcTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('test-partner-id', $request->getHeaderLine('partnerId'));
            $timestamp = $request->getHeaderLine('timestamp');
            $this->assertSame(md5('test-partner-id' . $timestamp . 'test-token'), $request->getHeaderLine('sign'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('900752733683', $body['mailNo']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/uc/track.json'));
        };

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('900752733683');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('快件已被揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('快件已签收，签收人：家人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 09:58:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：家人', $tracking->rawStatus);
        $this->assertSame('uc', $tracking->carrierCode);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0000',
                'msg' => '成功',
                'data' => [
                    'traces' => [
                        ['acceptTime' => '2026-08-15 09:58:00', 'acceptAddress' => '杭州市', 'remark' => '快件已签收'],
                        ['acceptTime' => '2026-08-12 16:30:00', 'acceptAddress' => '上海市', 'remark' => '快件已被揽收'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('900752733683');

        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], '{}');

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('900752733683');
            $this->fail('expected AuthException for HTTP 403');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[UC 403]', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['code' => '10001', 'msg' => '签名错误'], JSON_UNESCAPED_UNICODE));

        $adapter2 = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('900752733683');
            $this->fail('expected AuthException for auth error code');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[UC 10001]', $e->getMessage());
        }
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('900752733683');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[UC 500]', $e->getMessage());
        }
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/uc/empty.json'));

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('900752733683');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/uc/error.json'));

        $adapter = new Uc(
            new Config(['uc' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('900752733683');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[UC 20001]', $e->getMessage());
        }
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Uc(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('UC createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('900752733683'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('UC createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('UC subscribe 待实现', $e->getMessage());
        }
    }
}
