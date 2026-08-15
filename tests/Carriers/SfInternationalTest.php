<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\SfInternational;
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

final class SfInternationalTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): SfInternational
    {
        return new SfInternational(
            new Config(['sf-international' => ['partner_id' => 'test-sf-intl-partner', 'checkword' => 'test-sf-intl-checkword']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://sfapi.sf-express.com/std/service',
                (string) $request->getUri(),
            );
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('EXP_RECE_SEARCH_ROUTES', $body);
            $this->assertStringContainsString('SF1234567890123', $body);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/sf-international/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('SF1234567890123');

        $this->assertSame('sf-international', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('深圳市', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('快件已签收', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-13T18:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('8000', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'success' => true,
                'msgData' => [
                    'routeResps' => [
                        [
                            'mailNo' => 'SF1234567890123',
                            'routes' => [
                                ['acceptTime' => '2026-08-13 18:00:00', 'acceptAddress' => 'Los Angeles', 'remark' => '快件已签收', 'opcode' => '8000'],
                                ['acceptTime' => '2026-08-11 10:00:00', 'acceptAddress' => '深圳市', 'remark' => '快件已揽收', 'opcode' => '50'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('SF1234567890123');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('快件已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sf-international/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('SF1234567890123');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sf-international/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SF-INTERNATIONAL 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('SF1234567890123');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[SF-INTERNATIONAL 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('SF1234567890123');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SF-INTERNATIONAL] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('SF1234567890123');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('sf-international createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('SF1234567890123'));
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
