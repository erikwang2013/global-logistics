<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Dainiao;
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

final class DainiaoTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Dainiao
    {
        return new Dainiao(
            new Config(['dainiao' => [
                'logistic_provider_id' => 'test-provider-id',
                'secret_key' => 'test-secret-key',
            ]]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://edi.xpm.cainiao.com/ext/gateway/ediStandardTraceQuery/ediStandardTraceQuery/api',
                (string) $request->getUri(),
            );
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            parse_str((string) $request->getBody(), $form);
            $this->assertSame('test-provider-id', $form['logistic_provider_id']);
            $this->assertSame('689012345678', json_decode($form['logistics_interface'], true)['mailNo']);
            $this->assertSame(
                base64_encode(md5($form['logistics_interface'] . 'test-secret-key', true)),
                $form['data_digest'],
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dainiao/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('689012345678');

        $this->assertSame('dainiao', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('包裹已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('杭州市余杭区', $tracking->events[0]->location);
        $this->assertSame('2026-08-12 08:40:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('包裹已签收，签收人：前台', $tracking->events[3]->description);
        $this->assertSame('包裹已签收，签收人：前台', $tracking->latestDescription);
        $this->assertSame('2026-08-15 14:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('包裹已签收，签收人：前台', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'success' => true,
                'data' => [
                    'traces' => [
                        ['time' => '2026-08-15 14:30:00', 'desc' => '包裹已签收', 'location' => '上海市'],
                        ['time' => '2026-08-12 08:40:00', 'desc' => '包裹已揽收', 'location' => '杭州市'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('689012345678');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('包裹已揽收', $tracking->events[0]->description);
        $this->assertSame('包裹已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/dainiao/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('689012345678');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/dainiao/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[DAINIAO WAYBILL_NO_NOT_FOUND] 运单号不存在');

        $this->makeAdapter($http)->queryTrack('689012345678');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[DAINIAO 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('689012345678');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[DAINIAO] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('689012345678');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('dainiao createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('689012345678'));
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
