<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Sure;
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

final class SureTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx', (string) $request->getUri());
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            parse_str((string) $request->getBody(), $parsed);
            $this->assertSame('1002', $parsed['RequestType']);
            $this->assertSame('2', $parsed['DataType']);
            $this->assertSame('test-ebusiness-id', $parsed['EBusinessID']);

            $content = json_decode($parsed['RequestData'], true);
            $this->assertSame('SURE', $content['ShipperCode']);
            $this->assertSame('880488757848', $content['LogisticCode']);
            $this->assertSame('1234', $content['CustomerName']);

            $this->assertSame(base64_encode(md5($parsed['RequestData'] . 'test-app-key', true)), $parsed['DataSign']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/sure/track.json'));
        };

        $adapter = new Sure(
            new Config(['sure' => [
                'ebusiness_id' => 'test-ebusiness-id',
                'app_key' => 'test-app-key',
                'customer_name' => '1234',
            ]]),
            $http,
        );

        $tracking = $adapter->queryTrack('880488757848');

        $this->assertSame('sure', $tracking->carrierCode);
        $this->assertSame('880488757848', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('快件已被揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('已签收,签收人是转同行', $tracking->latestDescription);
        $this->assertSame('2026-08-15 09:58:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('已签收,签收人是转同行', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'Success' => true,
                'Traces' => [
                    ['AcceptTime' => '2026-08-15 09:58:00', 'AcceptStation' => '快件已签收'],
                    ['AcceptTime' => '2026-08-12 16:30:00', 'AcceptStation' => '快件已被揽收'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('880488757848');

        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sure/empty.json'));

        $adapter = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('880488757848');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sure/error.json'));

        $adapter = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        try {
            $adapter->queryTrack('880488757848');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[SURE] 无查询结果', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        try {
            $adapter->queryTrack('880488757848');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[SURE 401] 认证失败', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['Success' => false, 'Reason' => '验证签名失败'], JSON_UNESCAPED_UNICODE));

        $adapter2 = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('880488757848');
            $this->fail('expected AuthException for sign error');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[SURE] 验证签名失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Sure(
            new Config(['sure' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('880488757848');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Sure(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SURE createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('880488757848'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SURE createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('SURE subscribe 待实现', $e->getMessage());
        }
    }
}
