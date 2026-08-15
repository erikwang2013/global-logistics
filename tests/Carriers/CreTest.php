<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Cre;
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

final class CreTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Cre
    {
        return new Cre(
            new Config(['cre' => [
                'partner_id' => 'test-partner-id',
                'token' => 'test-token',
                'phone_suffix' => '8888',
            ]]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://open.95572.com/api/track/query', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('test-partner-id', $request->getHeaderLine('partnerId'));
            $timestamp = $request->getHeaderLine('timestamp');
            $this->assertSame(md5('test-partner-id' . $timestamp . 'test-token'), $request->getHeaderLine('sign'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('K12345678901', $body['billNo']);
            $this->assertSame('8888', $body['phoneSuffix']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/cre/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('K12345678901');

        $this->assertSame('cre', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('中铁快运已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('北京市', $tracking->events[0]->location);
        $this->assertSame('2026-08-12 10:15:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('货物已签收，签收人：本人', $tracking->events[3]->description);
        $this->assertSame('货物已签收，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 09:52:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('货物已签收，签收人：本人', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0000',
                'msg' => '成功',
                'data' => [
                    'traces' => [
                        ['acceptTime' => '2026-08-15 09:52:00', 'acceptAddress' => '郑州市', 'remark' => '货物已签收'],
                        ['acceptTime' => '2026-08-12 10:15:00', 'acceptAddress' => '北京市', 'remark' => '中铁快运已揽收'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('K12345678901');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('中铁快运已揽收', $tracking->events[0]->description);
        $this->assertSame('货物已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cre/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('K12345678901');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cre/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CRE 20002] 运单号不存在或已超出查询有效期');

        $this->makeAdapter($http)->queryTrack('K12345678901');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[CRE 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('K12345678901');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CRE] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('K12345678901');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('cre createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('K12345678901'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }
}
