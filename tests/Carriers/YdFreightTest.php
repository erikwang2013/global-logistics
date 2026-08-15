<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\YdFreight;
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

final class YdFreightTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): YdFreight
    {
        return new YdFreight(
            new Config(['yd-freight' => [
                'ebusiness_id' => 'test-ebusiness-id',
                'app_key' => 'test-app-key',
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
                'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx',
                (string) $request->getUri(),
            );
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));

            parse_str((string) $request->getBody(), $form);
            $this->assertSame('test-ebusiness-id', $form['EBusinessID']);
            $this->assertSame('1002', $form['RequestType']);
            $requestData = json_decode($form['RequestData'], true);
            $this->assertSame('YDKY', $requestData['ShipperCode']);
            $this->assertSame('3130759476612', $requestData['LogisticCode']);
            $this->assertSame(
                base64_encode(md5($form['RequestData'] . 'test-app-key', true)),
                $form['DataSign'],
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yd-freight/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('3130759476612');

        $this->assertSame('yd-freight', $tracking->carrierCode);
        $this->assertSame('3130759476612', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('韵达快运已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('2026-08-12 09:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('快件已签收，签收人：本人', $tracking->latestDescription);
        $this->assertSame('2026-08-15 10:12:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：本人', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'EBusinessID' => '1264783',
                'Success' => true,
                'ShipperCode' => 'YDKY',
                'State' => '3',
                'Traces' => [
                    ['AcceptTime' => '2026-08-15 10:12:00', 'AcceptStation' => '快件已签收', 'Remark' => ''],
                    ['AcceptTime' => '2026-08-12 09:30:00', 'AcceptStation' => '韵达快运已揽收', 'Remark' => ''],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('3130759476612');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('韵达快运已揽收', $tracking->events[0]->description);
        $this->assertSame('快件已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd-freight/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('3130759476612');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd-freight/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YD-FREIGHT] 查无此单');

        $this->makeAdapter($http)->queryTrack('3130759476612');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[YD-FREIGHT 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('3130759476612');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[YD-FREIGHT] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('3130759476612');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('yd-freight createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('3130759476612'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('yd-freight createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('yd-freight subscribe 待实现', $e->getMessage());
        }
    }
}
