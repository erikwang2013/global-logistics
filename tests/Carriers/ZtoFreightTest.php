<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\ZtoFreight;
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

final class ZtoFreightTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): ZtoFreight
    {
        return new ZtoFreight(
            new Config(['zto-freight' => [
                'company_id' => 'test-company-id',
                'app_secret' => 'test-app-secret',
                'phone_suffix' => '1234',
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
                'https://api.zto.com/zto.merchant.waybill.track.query',
                (string) $request->getUri(),
            );
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            parse_str((string) $request->getBody(), $form);
            $this->assertSame('test-company-id', $form['company_id']);
            $requestData = json_decode($form['request_data'], true);
            $this->assertSame('320000038967', $requestData['billCode']);
            $this->assertSame('1234', $requestData['phoneSuffix']);
            $this->assertSame(
                base64_encode(md5($form['request_data'] . 'test-app-secret', true)),
                $form['data_digest'],
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/zto-freight/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('320000038967');

        $this->assertSame('zto-freight', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('义乌市', $tracking->events[0]->location);
        $this->assertSame('2026-08-12 09:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('快件已签收，签收人：本人', $tracking->events[3]->description);
        $this->assertSame('快件已签收，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:12:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：本人', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'status' => true,
                'statusCode' => '200',
                'message' => '成功',
                'data' => [
                    'traces' => [
                        ['time' => '2026-08-15 10:12:00', 'desc' => '快件已签收', 'location' => '杭州市'],
                        ['time' => '2026-08-12 09:30:00', 'desc' => '快件已揽收', 'location' => '义乌市'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('320000038967');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('快件已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/zto-freight/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('320000038967');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/zto-freight/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ZTOFREIGHT E404] 运单号不存在');

        $this->makeAdapter($http)->queryTrack('320000038967');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[ZTOFREIGHT 403] 认证失败');

        $this->makeAdapter($http)->queryTrack('320000038967');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ZTOFREIGHT] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('320000038967');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('zto-freight createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('320000038967'));
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
