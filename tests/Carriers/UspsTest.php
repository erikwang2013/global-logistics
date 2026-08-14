<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Usps;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UspsTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());

            $query = Query::parse((string) $request->getUri()->getQuery());
            $this->assertSame('TrackV2', $query['API'] ?? null);

            $root = simplexml_load_string($query['XML'] ?? '');
            $this->assertSame('test-user', (string) $root['USERID']);
            $this->assertSame('9400111899223197448523', (string) $root->TrackID['ID']);

            return new Response(200, ['Content-Type' => 'application/xml'],
                file_get_contents(__DIR__ . '/../fixtures/usps/track.xml'));
        };

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $tracking = $adapter->queryTrack('9400111899223197448523');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Picked up', $tracking->events[0]->description);
        $this->assertSame('MEMPHIS', $tracking->events[0]->location);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertSame('BERLIN', $tracking->events[1]->location);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackEscapesQuotesInXmlAttributes(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = Query::parse((string) $request->getUri()->getQuery());
            $root = simplexml_load_string($query['XML'] ?? '');
            $this->assertNotFalse($root);
            $this->assertSame('test-user', (string) $root['USERID']);
            $this->assertSame('9400111899223197448523"', (string) $root->TrackID['ID']);

            return new Response(200, ['Content-Type' => 'application/xml'],
                file_get_contents(__DIR__ . '/../fixtures/usps/track.xml'));
        };

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $tracking = $adapter->queryTrack('9400111899223197448523"');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/usps/empty.xml'));

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('9400111899223197448523');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/usps/error.xml'));

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[USPS 80040B1A] Authorization failure.');

        $adapter->queryTrack('9400111899223197448523');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'], 'not xml at all');

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');

        $adapter->queryTrack('9400111899223197448523');
    }

    public function testUnmatchedDetailLineSkipped(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <TrackResponse>
                <TrackInfo ID="9400111899223197448523">
                    <TrackSummary>Shipping label created, USPS Awaiting Item</TrackSummary>
                    <TrackDetail>Shipping label created</TrackDetail>
                    <TrackDetail>August 14, 2026, 10:00 am, Picked up, MEMPHIS</TrackDetail>
                    <TrackDetail>August 15, 2026, 6:30 pm, Delivered, BERLIN</TrackDetail>
                </TrackInfo>
            </TrackResponse>
            XML);

        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            $http,
        );

        $tracking = $adapter->queryTrack('9400111899223197448523');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new Usps(
            new Config(['usps' => ['user_id' => 'test-user']]),
            new FakeHttpClient(),
        );

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('9400111899223197448523'));
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
