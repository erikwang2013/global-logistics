<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\JapanPost;
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

final class JapanPostTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): JapanPost
    {
        return new JapanPost(
            new Config(['japan-post' => ['user_id' => 'test-user']]),
            $http,
        );
    }

    public function testQueryTrackParsesAndReversesDescendingRecords(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertStringContainsString('https://trackings.post.japanpost.jp/services/srv/search/direct', (string) $request->getUri());
            $this->assertStringContainsString('requestNo1=EJ123456789JP', (string) $request->getUri());
            $this->assertStringContainsString('locale=en', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/xml'],
                file_get_contents(__DIR__ . '/../fixtures/japan-post/track.xml'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('EJ123456789JP');

        $this->assertSame('japan-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        // fixture 按降序（最新在前），适配器应反转为升序
        $this->assertSame('Posting', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Tokyo', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-13T09:00:00', $tracking->events[0]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('In transit', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Osaka', $tracking->events[1]->location);
        $this->assertSame('Delivery', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Tokyo', $tracking->events[2]->location);
        $this->assertSame('2026-08-15T10:00:00', $tracking->events[2]->occurredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivery', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-15T10:00:00', $tracking->deliveredAt?->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivery', $tracking->rawStatus);
        $this->assertSame('4', $tracking->raw['records'][0]['statusCd'] ?? null);
    }

    public function testQueryTrackThrowsWhenNoRecords(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/japan-post/empty.xml'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('EJ123456789JP');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/xml'], '');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[JAPAN-POST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('EJ123456789JP');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, ['Content-Type' => 'application/xml'], 'oops');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[JAPAN-POST 500] 接口错误');

        $this->makeAdapter($http)->queryTrack('EJ123456789JP');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'], 'not xml at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[JAPAN-POST] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('EJ123456789JP');
    }

    public function testMapStatusVariants(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <srvData>
                <result>
                    <requestNo>EJ123456789JP</requestNo>
                    <records>
                        <record><statusCd>1</statusCd><statusName>Acceptance</statusName><officeName>Tokyo</officeName><acceptDt>2026-08-10 10:00:00</acceptDt></record>
                        <record><statusCd>2</statusCd><statusName>Arrival</statusName><officeName>Osaka</officeName><acceptDt>2026-08-11 10:00:00</acceptDt></record>
                        <record><statusCd>2</statusCd><statusName>Departure</statusName><officeName>Osaka</officeName><acceptDt>2026-08-12 10:00:00</acceptDt></record>
                        <record><statusCd>3</statusCd><statusName>Out of delivery</statusName><officeName>Tokyo</officeName><acceptDt>2026-08-13 10:00:00</acceptDt></record>
                        <record><statusCd>5</statusCd><statusName>Held</statusName><officeName>Tokyo</officeName><acceptDt>2026-08-14 10:00:00</acceptDt></record>
                        <record><statusCd>6</statusCd><statusName>Return</statusName><officeName>Tokyo</officeName><acceptDt>2026-08-15 10:00:00</acceptDt></record>
                        <record><statusCd>4</statusCd><statusName>お届け</statusName><officeName>Tokyo</officeName><acceptDt>2026-08-16 10:00:00</acceptDt></record>
                    </records>
                </result>
            </srvData>
            XML;

            return new Response(200, ['Content-Type' => 'application/xml'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('EJ123456789JP');

        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[2]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[3]->status);
        $this->assertSame(TrackStatus::EXCEPTION, $tracking->events[4]->status);
        $this->assertSame(TrackStatus::RETURNED, $tracking->events[5]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[6]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('japan-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('EJ123456789JP'));
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
