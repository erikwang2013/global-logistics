<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Ctt;
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

final class CttTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://appserver.ctt.pt/CustomerArea/screenservices/CustomerArea/CustomerArea/PublicArea_Detail/DataActionGetObjectEventsByInputObjectCode',
                (string) $request->getUri()
            );
            $this->assertSame('test-token', $request->getHeaderLine('X-CSRFToken'));
            $this->assertSame('test-cookie', $request->getHeaderLine('Cookie'));
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('RR123456789PT', $body['screenData']['variables']['ObjectCodeInput']);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ctt/track.json'));
        };

        $adapter = new Ctt(new Config([
            'ctt' => ['csrf_token' => 'test-token', 'cookie' => 'test-cookie'],
        ]), $http);

        $tracking = $adapter->queryTrack('RR123456789PT');

        $this->assertSame('ctt', $tracking->carrierCode);
        $this->assertSame('RR123456789PT', $tracking->trackingNo);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[0]->status);
        $this->assertSame('Objeto aceite na rede', $tracking->events[0]->description);
        $this->assertSame('2026-08-11 08:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame('Objeto entregue ao destinatário', $tracking->latestDescription);
        $this->assertSame('2026-08-14 12:10:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Entregue', $tracking->rawStatus);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'data' => [
                    'ObjectEventsFromQuery' => [
                        'Found' => true,
                        'Events' => [
                            'List' => [
                                [
                                    'State' => 'Entregue',
                                    'Event' => 'Objeto entregue ao destinatário',
                                    'DateTime' => '14-08-2026 12:10',
                                ],
                                [
                                    'State' => 'Em Trânsito',
                                    'Event' => 'Objeto aceite na rede',
                                    'DateTime' => '11-08-2026 08:30',
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ctt(new Config([]), $http);

        $tracking = $adapter->queryTrack('RR123456789PT');

        $this->assertSame('2026-08-11 08:30:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ctt/empty.json'));

        $adapter = new Ctt(new Config([]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('RR123456789PT');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ctt/error.json'));

        $adapter = new Ctt(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789PT');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CTT 400] 接口错误', $e->getMessage());
        }
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], 'unauthorized');

        $adapter = new Ctt(new Config([]), $http);

        try {
            $adapter->queryTrack('RR123456789PT');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CTT 401] 认证失败', $e->getMessage());
        }
    }

    public function testNonArrayBodyThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Ctt(new Config([]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('RR123456789PT');
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Ctt(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ctt createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RR123456789PT'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ctt createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('ctt subscribe 待实现', $e->getMessage());
        }
    }
}
