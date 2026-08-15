<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Bpost;
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

final class BpostTest extends TestCase
{
    private function makeAdapter(FakeHttpClient $http): Bpost
    {
        return new Bpost(
            new Config(['bpost' => ['account_id' => '123456', 'password' => 'test-pass']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame(
                'https://api.bpost.be/services/shm/123456/tracks/RB123456789BE',
                (string) $request->getUri(),
            );
            $this->assertSame('Basic ' . base64_encode('123456:test-pass'), $request->getHeaderLine('Authorization'));
            $this->assertSame(
                'application/vnd.bpost.shm-trackResponse-v3+XML',
                $request->getHeaderLine('Accept'),
            );

            return new Response(200, ['Content-Type' => 'application/vnd.bpost.shm-trackResponse-v3+XML'],
                file_get_contents(__DIR__ . '/../fixtures/bpost/track.xml'));
        };

        $tracking = $this->makeAdapter($http)->queryTrack('RB123456789BE');

        $this->assertSame('bpost', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(4, $tracking->events);
        $this->assertSame('Parcel accepted at the post office', $tracking->events[0]->description);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Brussels', $tracking->events[0]->location);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->events[0]->occurredAt);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[2]->status);
        $this->assertSame('Parcel delivered', $tracking->events[3]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[3]->status);
        $this->assertSame('Parcel delivered', $tracking->latestDescription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tracking->deliveredAt);
        $this->assertSame('2026-08-14T17:45:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('DLVD', $tracking->rawStatus);
        $this->assertSame('RB123456789BE', $tracking->raw['barcode']);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<trackingInfo xmlns="http://schema.post.be/shm/deepintegration/v3/">' .
                '<barcode>RB123456789BE</barcode><events>' .
                '<event><code>DLVD</code><description>Parcel delivered</description><date>2026-08-14T17:45:00+02:00</date><location>Ghent</location></event>' .
                '<event><code>ACCEPT</code><description>Parcel accepted at the post office</description><date>2026-08-12T09:00:00+02:00</date><location>Brussels</location></event>' .
                '</events></trackingInfo>';

            return new Response(200, ['Content-Type' => 'application/xml'], $body);
        };

        $tracking = $this->makeAdapter($http)->queryTrack('RB123456789BE');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Parcel accepted at the post office', $tracking->events[0]->description);
        $this->assertSame('Parcel delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/bpost/empty.xml'));

        $this->expectException(TrackingNotFoundException::class);

        $this->makeAdapter($http)->queryTrack('RB123456789BE');
    }

    public function testQueryTrackMapsBusinessError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(404, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/bpost/error.xml'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[BPOST 404] 接口错误');

        $this->makeAdapter($http)->queryTrack('RB123456789BE');
    }

    public function testQueryTrackMapsAuthError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/xml'], '<xml/>');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[BPOST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RB123456789BE');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'], 'not xml at all');

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[BPOST] 响应解析失败');

        $this->makeAdapter($http)->queryTrack('RB123456789BE');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('bpost createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RB123456789BE'));
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
