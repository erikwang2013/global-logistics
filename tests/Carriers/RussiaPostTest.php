<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\RussiaPost;
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

final class RussiaPostTest extends TestCase
{
    private function adapter(FakeHttpClient $http): RussiaPost
    {
        return new RussiaPost(
            new Config(['russia-post' => ['login' => 'login-1', 'password' => 'pass-1']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame(
                'Basic ' . base64_encode('login-1:pass-1'),
                $request->getHeaderLine('Authorization'),
            );
            $this->assertStringContainsString('/tracking-web/api/operation-history', (string) $request->getUri());
            $this->assertStringContainsString('trackingNumber=RA123456789RU', (string) $request->getUri());
            $this->assertStringContainsString('language=RUS', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/russia-post/track.json'));
        };

        $tracking = $this->adapter($http)->queryTrack('RA123456789RU');

        $this->assertSame('russia-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Приём Обработка', $tracking->events[0]->description);
        $this->assertSame('МОСКВА', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('Обработка Сортировка', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Прибыло в место вручения', $tracking->events[2]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Вручение Адресату', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-12 12:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('Вручение Адресату', $tracking->rawStatus);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[RUSSIA-POST 401] 认证失败');

        $this->adapter($http)->queryTrack('RA123456789RU');
    }

    public function testQueryTrackThrowsOnServerError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(503, ['Content-Type' => 'text/plain'], 'Service Unavailable');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[RUSSIA-POST 503] 接口错误');

        $this->adapter($http)->queryTrack('RA123456789RU');
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/russia-post/empty.json'));

        $this->expectException(TrackingNotFoundException::class);

        $this->adapter($http)->queryTrack('RA123456789RU');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/russia-post/error.json'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('Некорректный трек-номер');

        $this->adapter($http)->queryTrack('RA123456789RU');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], 'not json at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[RUSSIA-POST] 响应解析失败');

        $this->adapter($http)->queryTrack('RA123456789RU');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->adapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('russia-post createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RA123456789RU'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('russia-post createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('russia-post subscribe 待实现', $e->getMessage());
        }
    }
}
