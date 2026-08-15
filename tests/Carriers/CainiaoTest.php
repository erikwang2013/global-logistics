<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Cainiao;
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

final class CainiaoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            $params = [];
            parse_str((string) $request->getBody(), $params);
            $this->assertSame('edi_test_json', $params['logistic_provider_id']);
            $this->assertSame('CA123456789', json_decode($params['logistics_interface'], true)['mailNo']);
            $this->assertSame(
                base64_encode(md5($params['logistics_interface'] . 'test-secret', true)),
                $params['data_digest']
            );

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/cainiao/track.json'));
        };

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('CA123456789');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('2026-08-12 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertSame('2026-08-15 14:02:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收', $tracking->rawStatus);
        $this->assertSame('cainiao', $tracking->carrierCode);
    }

    public function testDescendingTracesAreReversed(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'success' => true,
                'logisticsTrajectories' => [
                    ['time' => '2026-08-15 14:02:00', 'desc' => '快件已签收', 'location' => '杭州市'],
                    ['time' => '2026-08-12 10:00:00', 'desc' => '快件已揽收', 'location' => '深圳市'],
                ],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('CA123456789');

        $this->assertSame('2026-08-12 10:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[1]->status);
    }

    public function testAuthFailuresThrowAuthException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, [], '{}');

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('CA123456789');
            $this->fail('expected AuthException for HTTP 401');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CAINIAO 401]', $e->getMessage());
        }

        $http2 = new FakeHttpClient();
        $http2->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['success' => false, 'errorCode' => 'SIGN_CHECK_FAIL', 'errorMsg' => '签名校验失败'],
                JSON_UNESCAPED_UNICODE));

        $adapter2 = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http2,
        );

        try {
            $adapter2->queryTrack('CA123456789');
            $this->fail('expected AuthException for sign check code');
        } catch (AuthException $e) {
            $this->assertStringContainsString('[CAINIAO SIGN_CHECK_FAIL]', $e->getMessage());
        }
    }

    public function testServerErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(500, [], 'server error');

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('CA123456789');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CAINIAO 500]', $e->getMessage());
        }
    }

    public function testEmptyTracesThrowsTrackingNotFoundException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cainiao/empty.json'));

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('CA123456789');
    }

    public function testBusinessErrorThrowsLogisticsException(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/cainiao/error.json'));

        $adapter = new Cainiao(
            new Config(['cainiao' => ['logistic_provider_id' => 'edi_test_json', 'secret_key' => 'test-secret']]),
            $http,
        );

        try {
            $adapter->queryTrack('CA123456789');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('[CAINIAO MAIL_NO_NOT_EXIST]', $e->getMessage());
        }
    }

    public function testUnimplementedMethodsThrow(): void
    {
        $adapter = new Cainiao(new Config([]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest([], []));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CAINIAO createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('CA123456789'));
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CAINIAO createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/callback');
            $this->fail('expected LogisticsException');
        } catch (LogisticsException $e) {
            $this->assertSame('CAINIAO subscribe 待实现', $e->getMessage());
        }
    }
}
