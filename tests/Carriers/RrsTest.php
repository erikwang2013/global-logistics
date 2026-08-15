<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Rrs;
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

final class RrsTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('http://58.56.128.10:19001/EAI/RoutingProxyService/EAI_REST_POST_ServiceRoot?INT_CODE=EAI_INT_1353', (string) $request->getUri());
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            parse_str((string) $request->getBody(), $parsed);
            $this->assertSame('rrs_statusback', $parsed['butype']);
            $this->assertSame('xml', $parsed['type']);
            $this->assertSame('<Code><expno>887654321098</expno></Code>', $parsed['content']);
            $this->assertSame(base64_encode(md5($parsed['content'] . 'test-key', true)), $parsed['sign']);

            return new Response(200, ['Content-Type' => 'text/xml'],
                file_get_contents(__DIR__ . '/../fixtures/rrs/track.xml'));
        };

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key', 'notifyid' => 'rrs-001', 'source' => 'test-source']]),
            $http,
        );

        $tracking = $adapter->queryTrack('887654321098');

        $this->assertSame('rrs', $tracking->carrierCode);
        $this->assertSame('887654321098', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('【青岛市】快件已揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('【济南市】快件已签收', $tracking->latestDescription);
        $this->assertSame('2026-08-15 09:58:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('【济南市】快件已签收', $tracking->rawStatus);
    }

    public function testDescendingNodesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'],
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <request>
                <flag>T</flag>
                <response>
                    <Realtime><nodemes>快件已签收</nodemes><operdate>2026-08-15</operdate><opertime>09:58:00</opertime></Realtime>
                    <Realtime><nodemes>快件已揽收</nodemes><operdate>2026-08-12</operdate><opertime>16:30:00</opertime></Realtime>
                </response>
                <msg>成功</msg>
            </request>
            XML);

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http,
        );

        $tracking = $adapter->queryTrack('887654321098');

        $this->assertSame('2026-08-12 16:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyNodesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'],
            file_get_contents(__DIR__ . '/../fixtures/rrs/empty.xml'));

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('887654321098');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'],
            file_get_contents(__DIR__ . '/../fixtures/rrs/error.xml'));

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http,
        );

        try {
            $adapter->queryTrack('887654321098');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[RRS] 单号不存在', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http,
        );

        try {
            $adapter->queryTrack('887654321098');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[RRS 401] 认证失败', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'],
            '<?xml version="1.0" encoding="UTF-8"?><request><flag>F</flag><msg>验证签名失败</msg></request>');

        $adapter2 = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('887654321098');
            $this->fail('expected AuthException for sign error');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[RRS] 验证签名失败', $e->getMessage());
        }
    }

    public function testNonXmlBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/plain'], 'not xml at all');

        $adapter = new Rrs(
            new Config(['rrs' => ['key_value' => 'test-key']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('887654321098');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Rrs(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('RRS createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('887654321098'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('RRS createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('RRS subscribe 待实现', $e->getMessage());
        }
    }
}
