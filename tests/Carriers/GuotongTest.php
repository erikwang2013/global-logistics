<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Guotong;
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

final class GuotongTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Guotong
    {
        return new Guotong(
            new Config(['guotong' => [
                'ebusiness_id' => 'test-ebusiness-id',
                'app_key' => 'test-app-key',
                'customer_name' => '1234',
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
            $this->assertSame('2', $form['DataType']);
            $requestData = json_decode($form['RequestData'], true);
            $this->assertSame('GTO', $requestData['ShipperCode']);
            $this->assertSame('123456789012', $requestData['LogisticCode']);
            $this->assertSame('1234', $requestData['CustomerName']);
            $this->assertSame(
                base64_encode(md5($form['RequestData'] . 'test-app-key', true)),
                $form['DataSign'],
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/guotong/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('123456789012');

        $this->assertSame('guotong', $tracking->carrierCode);
        $this->assertSame('123456789012', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('国通快递已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
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
                'EBusinessID' => '1264783',
                'Success' => true,
                'ShipperCode' => 'GTO',
                'State' => '3',
                'Traces' => [
                    ['AcceptTime' => '2026-08-15 10:12:00', 'AcceptStation' => '快件已签收', 'Remark' => ''],
                    ['AcceptTime' => '2026-08-12 09:30:00', 'AcceptStation' => '国通快递已揽收', 'Remark' => ''],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $tracking = $this->makeAdapter($http)->queryTrack('123456789012');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('国通快递已揽收', $tracking->events[0]->description);
        $this->assertSame('快件已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/guotong/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('123456789012');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/guotong/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[GUOTONG 400] 接口错误');

        $this->makeAdapter($http)->queryTrack('123456789012');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[GUOTONG 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('123456789012');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[GUOTONG] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('123456789012');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('guotong createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('123456789012'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('guotong createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertSame('guotong subscribe 待实现', $e->getMessage());
        }
    }
}
