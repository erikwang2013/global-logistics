<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Ht;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HtTest extends TestCase
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
            $this->assertSame('HT1234567890', $body['trackingNo']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ht/track.json'));
        };

        $adapter = new Ht(
            new Config(['ht' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('HT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('2026-08-14 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 18:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('0000', $tracking->rawStatus);
        $this->assertSame('ht', $tracking->carrierCode);
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
                        ['acceptTime' => '2026-08-15 18:30:00', 'acceptAddress' => '杭州市', 'remark' => '快件已签收'],
                        ['acceptTime' => '2026-08-14 10:00:00', 'acceptAddress' => '上海市', 'remark' => '快件已到达【上海转运中心】'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ht(
            new Config(['ht' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $tracking = $adapter->queryTrack('HT1234567890');

        $this->assertSame('2026-08-14 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testUnauthorizedThrowsAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{"code": "10001", "msg": "unauthorized"}');

        $adapter = new Ht(
            new Config(['ht' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $adapter->queryTrack('HT1234567890');
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Ht(
            new Config(['ht' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        try {
            $adapter->queryTrack('HT1234567890');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertInstanceOf(LogisticsException::class, $e);
            $this->assertStringContainsString('[HT 500]', $e->getMessage());
        }
    }

    public function testNoTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['code' => '0000', 'msg' => '成功', 'data' => ['traces' => []]], JSON_UNESCAPED_UNICODE));

        $adapter = new Ht(
            new Config(['ht' => ['partner_id' => 'test-partner-id', 'token' => 'test-token']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('HT1234567890');
    }
}
