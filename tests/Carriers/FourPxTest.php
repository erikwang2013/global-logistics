<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\FourPx;
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

final class FourPxTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): FourPx
    {
        return new FourPx(
            new Config([
                'fourpx' => [
                    'app_key' => 'test-app-key',
                    'app_secret' => 'test-app-secret',
                    'access_token' => 'test-access-token',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParsesWithSignature(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

            $query = [];
            parse_str((string) $request->getUri()->getQuery(), $query);
            $this->assertSame('tr.order.tracking.get', $query['method'] ?? null);
            $this->assertSame('test-app-key', $query['app_key'] ?? null);
            $this->assertSame('1.0', $query['v'] ?? null);
            $this->assertSame('json', $query['format'] ?? null);
            $this->assertSame('test-access-token', $query['access_token'] ?? null);
            $this->assertArrayHasKey('timestamp', $query);

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('4PX1234567890', $body['trackingNumber'] ?? null);

            // sign = md5(公共参数按 key 升序去分隔符拼接 + body + appSecret)，排除 access_token
            $signSource = 'app_keytest-app-key'
                . 'formatjson'
                . 'methodtr.order.tracking.get'
                . 'timestamp' . $query['timestamp']
                . 'v1.0'
                . json_encode(['trackingNumber' => '4PX1234567890'], JSON_UNESCAPED_UNICODE)
                . 'test-app-secret';
            $this->assertSame(md5($signSource), $query['sign'] ?? '');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/fourpx/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('4PX1234567890');

        $this->assertSame('fourpx', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('快件已收件', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('深圳市', $tracking->events[0]->location);
        $this->assertSame('2026-08-13 09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('洛杉矶', $tracking->events[2]->location);
        $this->assertSame('快件已签收，签收人：JOHN', $tracking->latestDescription);
        $this->assertSame('2026-08-15 18:30:00', $tracking->deliveredAt?->format('Y-m-d H:i:s'));
        $this->assertSame('已签收', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'result' => '1',
                'msg' => 'success',
                'data' => [
                    'trackDetails' => [
                        ['trackTime' => '2026-08-15 18:30:00', 'trackAddress' => '洛杉矶', 'trackStatus' => '已签收', 'trackDesc' => '快件已签收'],
                        ['trackTime' => '2026-08-14 03:00:00', 'trackAddress' => '香港', 'trackStatus' => '运输中', 'trackDesc' => '快件已到达香港'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('4PX1234567890');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('2026-08-14 03:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/fourpx/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('4PX1234567890');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/fourpx/error.json'));

        try {
            $this->makeAdapter($http)->queryTrack('4PX1234567890');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertInstanceOf(LogisticsException::class, $e);
            $this->assertStringContainsString('[4PX 0] tracking number not found', $e->getMessage());
        }
    }

    public function testUnauthorizedThrowsAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{"result": "0", "msg": "unauthorized"}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[4PX 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('4PX1234567890');
    }

    public function testNonArrayResponseThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[4PX] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('4PX1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('4PX createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('4PX1234567890'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('4PX createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('4PX subscribe 待实现', $e->getMessage());
        }
    }
}
