<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Purolator;
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

final class PurolatorTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Purolator
    {
        return new Purolator(
            new Config([
                'purolator' => [
                    'production_key' => 'testkey',
                    'password' => 'testpass',
                    'group_id' => 'TESTGROUP',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://webservices.purolator.com/PWS/V1/Tracking/TrackingService.asmx',
                (string) $request->getUri(),
            );
            $this->assertSame('Basic ' . base64_encode('testkey:testpass'), $request->getHeaderLine('Authorization'));
            $this->assertSame('text/xml; charset=utf-8', $request->getHeaderLine('Content-Type'));
            $this->assertSame('"http://purolator.com/pws/service/v1/TrackByPin"', $request->getHeaderLine('SOAPAction'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('TrackByPinRequest', $body);
            $this->assertStringContainsString('<pws:Value>329014521622</pws:Value>', $body);
            $this->assertStringContainsString('<pws:GroupID>TESTGROUP</pws:GroupID>', $body);

            return new Response(200, ['Content-Type' => 'text/xml'],
                file_get_contents(__DIR__ . '/../fixtures/purolator/track.xml'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('329014521622');

        $this->assertSame('purolator', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Toronto, ON', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T17:45:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('D', $tracking->rawStatus);
        $this->assertSame('329014521622', $tracking->raw['pin']);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = '<?xml version="1.0" encoding="utf-8"?>' .
                '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
                '<soap:Body><TrackByPinResponse xmlns="http://purolator.com/pws/datatypes/v1">' .
                '<ResponseInformation><Errors /></ResponseInformation>' .
                '<TrackingInformation><PIN><Value>329014521622</Value></PIN><Scans>' .
                '<Scan><ScanType>D</ScanType><Date>2026-08-14T17:45:00</Date><Description>Delivered</Description><Location>Vancouver, BC</Location></Scan>' .
                '<Scan><ScanType>P</ScanType><Date>2026-08-12T09:00:00</Date><Description>Picked up</Description><Location>Toronto, ON</Location></Scan>' .
                '</Scans></TrackingInformation></TrackByPinResponse></soap:Body></soap:Envelope>';

            return new Response(200, ['Content-Type' => 'text/xml'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('329014521622');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame('Delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'],
            file_get_contents(__DIR__ . '/../fixtures/purolator/empty.xml'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('329014521622');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'text/xml'],
            file_get_contents(__DIR__ . '/../fixtures/purolator/error.xml'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[PUROLATOR 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('329014521622');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/xml'], '<soap:Envelope/>');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[PUROLATOR 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('329014521622');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'text/xml'], 'not xml at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[PUROLATOR] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('329014521622');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('purolator createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('329014521622'));
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
