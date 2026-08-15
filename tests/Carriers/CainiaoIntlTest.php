<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\CainiaoIntl;
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

final class CainiaoIntlTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): CainiaoIntl
    {
        return new CainiaoIntl(new Config([]), $http);
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('LP00123456789012', $this->parseQuery($request->getUri()->getQuery())['mailNoList'] ?? null);
            $this->assertSame(
                'https://global.cainiao.com/global/detail.json',
                $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . $request->getUri()->getPath(),
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/cainiao-intl/track.json'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('LP00123456789012');

        $this->assertSame('cainiao-intl', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('已揽收', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('深圳 深圳转运中心', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame('2026-08-05T10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('已签收', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('已签收', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T14:30:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('SIGNED', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_encode([
                'success' => true,
                'data' => [
                    'mailNos' => [
                        [
                            'mailNo' => 'LP00123456789012',
                            'traceDTOList' => [
                                ['status' => 'SIGNED', 'statusDesc' => '已签收', 'timeDesc' => '2026-08-14 14:30:00', 'cityName' => '伦敦', 'orgName' => '伦敦'],
                                ['status' => 'GOT', 'statusDesc' => '已揽收', 'timeDesc' => '2026-08-05 10:00:00', 'cityName' => '深圳', 'orgName' => '深圳转运中心'],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('LP00123456789012');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('已揽收', $tracking->events[0]->description);
        $this->assertSame('已签收', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cainiao-intl/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('LP00123456789012');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cainiao-intl/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CAINIAO-INTL SERVICE_ERROR] 服务异常，请稍后重试');

        $this->makeAdapter($http)->queryTrack('LP00123456789012');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[CAINIAO-INTL 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('LP00123456789012');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[CAINIAO-INTL] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('LP00123456789012');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('cainiao-intl createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('LP00123456789012'));
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

    /** @return array<string, string> */
    private function parseQuery(string $query): array
    {
        parse_str($query, $params);

        return $params;
    }
}
