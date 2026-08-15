<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\KoreaPost;
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

final class KoreaPostTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            $this->assertStringContainsString('serviceKey=test-service-key', $request->getUri()->getQuery());
            $this->assertStringContainsString('rgist=RR123456789KR', $request->getUri()->getQuery());

            return new Response(200, ['Content-Type' => 'application/xml;charset=utf-8'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): KoreaPost
    {
        return new KoreaPost(
            new Config(['korea-post' => ['service_key' => 'test-service-key']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/korea-post/track.xml')));

        $tracking = $adapter->queryTrack('RR123456789KR');

        $this->assertSame('korea-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('우편물 접수', $tracking->events[0]->description);
        $this->assertSame('서울강남우체국', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('수취인 배달 완료', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('배달완료', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<response>'
            . '<comMsgHeader><returnReasonCode>00</returnReasonCode><errMsg>NORMAL SERVICE</errMsg></comMsgHeader>'
            . '<msgBody><items><item>'
            . '<dlvySttus>배달완료</dlvySttus>'
            . '<list>'
            . '<item><processDe>2026.08.14 10:00</processDe><processSttus>배달완료</processSttus><nowLc>서울</nowLc><detailDc>수취인 배달 완료</detailDc></item>'
            . '<item><processDe>2026.08.08 09:00</processDe><processSttus>접수</processSttus><nowLc>서울강남우체국</nowLc><detailDc>우편물 접수</detailDc></item>'
            . '</list>'
            . '</item></items></msgBody>'
            . '</response>';

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('RR123456789KR');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('우편물 접수', $tracking->events[0]->description);
        $this->assertSame('수취인 배달 완료', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/xml'], '<error>Unauthorized</error>');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[KOREA-POST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RR123456789KR');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/korea-post/error.xml')));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[KOREA-POST] 배송조회 서비스 오류');

        $adapter->queryTrack('RR123456789KR');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/korea-post/empty.xml')));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('RR123456789KR');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not xml at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[KOREA-POST] 响应解析失败');

        $adapter->queryTrack('RR123456789KR');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('KOREA-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789KR'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('KOREA-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('KOREA-POST subscribe 待实现', $e->getMessage());
        }
    }
}
