<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Ontrac;
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

final class OntracTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Ontrac
    {
        return new Ontrac(
            new Config(['ontrac' => ['account_no' => 'TESTACCT', 'password' => 'test-pass']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https://www.ontrac.com/API_Shipping.asp', (string) $request->getUri());
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('strAccountNo=TESTACCT', $body);
            $this->assertStringContainsString('strPassPhrase=test-pass', $body);
            $this->assertStringContainsString('RequestType=3', $body);
            $this->assertStringContainsString('TrackingNo=C11031500001879', $body);

            return new Response(200, ['Content-Type' => 'application/xml'],
                file_get_contents(__DIR__ . '/../fixtures/ontrac/track.xml'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('C11031500001879');

        $this->assertSame('ontrac', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Los Angeles, CA 90001', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T17:45:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('Delivered', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = '<?xml version="1.0" encoding="UTF-8"?><Tracking><Track>' .
                '<TrackingNumber>C11031500001879</TrackingNumber>' .
                '<StatusMessage>Delivered</StatusMessage><Events>' .
                '<Event><DateTime>2026-08-14 17:45:00</DateTime><Description>Delivered</Description><City>SF</City><State>CA</State><Zip>94103</Zip></Event>' .
                '<Event><DateTime>2026-08-12 09:00:00</DateTime><Description>Picked up</Description><City>LA</City><State>CA</State><Zip>90001</Zip></Event>' .
                '</Events></Track></Tracking>';

            return new Response(200, ['Content-Type' => 'application/xml'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('C11031500001879');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/ontrac/empty.xml'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('C11031500001879');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/ontrac/error.xml'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ONTRAC 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('C11031500001879');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/xml'], '<Tracking/>');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[ONTRAC 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('C11031500001879');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'], 'not xml at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[ONTRAC] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('C11031500001879');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('ontrac createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('C11031500001879'));
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
