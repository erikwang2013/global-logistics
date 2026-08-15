<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Winit;
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

final class WinitTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Winit
    {
        return new Winit(
            new Config(['winit' => [
                'app_key' => 'test-app-key',
                'app_secret' => 'test-app-secret',
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ]]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://openapi.winit.com.cn/openapi/service', (string) $request->getUri());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('tracking.getOrderTracking', $body['action']);
            $this->assertSame('test-app-key', $body['app_key']);
            $this->assertSame('test-client-id', $body['client_id']);
            $this->assertSame('json', $body['format']);
            $this->assertSame('OWNERERP', $body['platform']);
            $this->assertSame('zh_CN', $body['language']);
            $this->assertSame('md5', $body['sign_method']);
            $this->assertSame('1.0', $body['version']);
            $this->assertSame('0B044518500034109567A', json_decode($body['data'], true)['trackingNOs']);

            $sign = $body['sign'];
            $clientSign = $body['client_sign'];
            unset($body['sign'], $body['client_sign']);
            ksort($body);
            $pairs = [];
            foreach ($body as $key => $value) {
                $pairs[] = $key . '=' . $value;
            }
            $joined = implode('&', $pairs);
            $this->assertSame(strtoupper(md5($joined . 'test-app-secret')), $sign);
            $this->assertSame(strtoupper(md5($joined . 'test-client-secret')), $clientSign);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/winit/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('0B044518500034109567A');

        $this->assertSame('winit', $tracking->carrierCode);
        $this->assertSame('0B044518500034109567A', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame('提交订单', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('2026-08-12 09:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Shenzhen', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('派送完成', $tracking->events[2]->description);
        $this->assertSame('派送完成', $tracking->latestDescription);
        $this->assertSame('2026-08-15 11:24:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('派送完成', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'msg' => null,
                'data' => [
                    [
                        'orderNo' => 'ID15110000000061ZZ',
                        'trackingNo' => '0B044518500034109567A',
                        'status' => '派送完成',
                        'trace' => [
                            [
                                'date' => '2026-08-15 11:24:00',
                                'location' => 'London',
                                'lastEvent' => 'Y',
                                'eventCode' => 'DLC',
                                'eventStatus' => 'Delivered',
                                'eventDescription' => '派送完成',
                            ],
                            [
                                'date' => '2026-08-12 09:00:00',
                                'location' => 'Shenzhen',
                                'lastEvent' => 'N',
                                'eventCode' => 'WFD',
                                'eventStatus' => 'Submitted',
                                'eventDescription' => '提交订单',
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('0B044518500034109567A');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('提交订单', $tracking->events[0]->description);
        $this->assertSame('派送完成', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/winit/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('0B044518500034109567A');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/winit/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[WINIT 1002] 跟踪号不存在');

        $this->makeAdapter($http)->queryTrack('0B044518500034109567A');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(403, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[WINIT 403] 认证失败');

        $this->makeAdapter($http)->queryTrack('0B044518500034109567A');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[WINIT] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('0B044518500034109567A');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('winit createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('0B044518500034109567A'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('winit createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('winit subscribe 待实现', $e->getMessage());
        }
    }
}
