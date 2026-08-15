<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\HongKongPost;
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

final class HongKongPostTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            $this->assertStringContainsString('text/xml', $request->getHeaderLine('Content-Type'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('getMTTInfo', $body);
            $this->assertStringContainsString('test-user', $body);
            $this->assertStringContainsString('test-hkp-id', $body);
            $this->assertStringContainsString('test-integrator', $body);
            $this->assertStringContainsString('RB123456789HK', $body);

            return new Response(200, ['Content-Type' => 'text/xml'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): HongKongPost
    {
        return new HongKongPost(
            new Config([
                'hong-kong-post' => [
                    'ecship_username' => 'test-user',
                    'hkp_id' => 'test-hkp-id',
                    'integrator_username' => 'test-integrator',
                ],
            ]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/hong-kong-post/track.xml')));

        $tracking = $adapter->queryTrack('RB123456789HK');

        $this->assertSame('hong-kong-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Item posted at post office', $tracking->events[0]->description);
        $this->assertSame('Hong Kong', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Item delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('3', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<ns2:getMTTInfoReturn xmlns:ns2="http://webservice.integrator.hkpost.com">'
            . '<errMessage>Success</errMessage><status>0</status><itemNo>RB123456789HK</itemNo>'
            . '<milestoneList>'
            . '<milestone><milestoneNo>2</milestoneNo><milestoneName>Delivered</milestoneName><eventDate>2026-08-14</eventDate><eventTime>10:00</eventTime><eventLocation>Hong Kong</eventLocation><milestoneDescription>Item delivered</milestoneDescription></milestone>'
            . '<milestone><milestoneNo>1</milestoneNo><milestoneName>Posted</milestoneName><eventDate>2026-08-08</eventDate><eventTime>09:00</eventTime><eventLocation>Hong Kong</eventLocation><milestoneDescription>Item posted at post office</milestoneDescription></milestone>'
            . '</milestoneList>'
            . '</ns2:getMTTInfoReturn>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('RB123456789HK');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Item posted at post office', $tracking->events[0]->description);
        $this->assertSame('Item delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'text/xml'], '<error>Unauthorized</error>');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[HONG-KONG-POST 401] 认证失败');

        $this->makeAdapter($http)->queryTrack('RB123456789HK');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/hong-kong-post/error.xml')));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[HONG-KONG-POST] Invalid item number');

        $adapter->queryTrack('RB123456789HK');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/hong-kong-post/empty.xml')));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('RB123456789HK');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not xml at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[HONG-KONG-POST] 响应解析失败');

        $adapter->queryTrack('RB123456789HK');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('HONG-KONG-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('RB123456789HK'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('HONG-KONG-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('HONG-KONG-POST subscribe 待实现', $e->getMessage());
        }
    }
}
