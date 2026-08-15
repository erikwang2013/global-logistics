<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Lht;
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

final class LhtTest extends TestCase
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
            $this->assertSame('test-ebusiness-id', $parsed['EBusinessID']);

            $content = json_decode($parsed['RequestData'], true);
            $this->assertSame('LHT', $content['ShipperCode']);
            $this->assertSame('512345678901', $content['LogisticCode']);

            $this->assertSame(base64_encode(md5($parsed['RequestData'] . 'test-app-key', true)), $parsed['DataSign']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/lht/track.json'));
        };

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'test-ebusiness-id', 'app_key' => 'test-app-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('512345678901');

        $this->assertSame('lht', $tracking->carrierCode);
        $this->assertSame('512345678901', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('快件已被揽收', $tracking->events[0]->description);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
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

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('512345678901');

        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/lht/empty.json'));

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('512345678901');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/lht/error.json'));

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        try {
            $adapter->queryTrack('512345678901');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[LHT] 接口调用超时', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        try {
            $adapter->queryTrack('512345678901');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[LHT 401] 认证失败', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['Success' => false, 'Reason' => '商户ID错误'], JSON_UNESCAPED_UNICODE));

        $adapter2 = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('512345678901');
            $this->fail('expected AuthException for merchant id error');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[LHT] 商户ID错误', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '<html>gateway</html>');

        $adapter = new Lht(
            new Config(['lht' => ['ebusiness_id' => 'id', 'app_key' => 'key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('512345678901');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Lht(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('LHT createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('512345678901'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('LHT createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('LHT subscribe 待实现', $e->getMessage());
        }
    }
}
