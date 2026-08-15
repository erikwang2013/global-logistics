<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\SwissPost;
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

final class SwissPostTest extends TestCase
{
    private function makeHttp(string $trackBody): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($trackBody) {
            if ($request->getUri()->getPath() === '/OAuth/token') {
                $this->assertStringContainsString('grant_type=client_credentials', (string) $request->getBody());
                $this->assertStringContainsString('client_id=test-client-id', (string) $request->getBody());
                $this->assertStringContainsString('client_secret=test-client-secret', (string) $request->getBody());
                $this->assertStringContainsString('scope=dcapi_track_parcels', (string) $request->getBody());

                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-swiss","expires_in":3600}');
            }

            $this->assertSame('Bearer tok-swiss', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('/track/v1/parcels/XX123456789CH', $request->getUri()->getPath());
            $this->assertStringContainsString('language=en', $request->getUri()->getQuery());

            return new Response(200, ['Content-Type' => 'application/json'], $trackBody);
        };

        return $http;
    }

    private function makeAdapter(FakeHttpClient $http): SwissPost
    {
        return new SwissPost(
            new Config(['swiss-post' => ['client_id' => 'test-client-id', 'client_secret' => 'test-client-secret']]),
            $http,
        );
    }

    public function testQueryTrackParses(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/swiss-post/track.json')));

        $tracking = $adapter->queryTrack('XX123456789CH');

        $this->assertSame('swiss-post', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(3, $tracking->events);
        $this->assertSame(TrackStatus::PENDING, $tracking->events[0]->status);
        $this->assertSame('Item posted', $tracking->events[0]->description);
        $this->assertSame('Bern', $tracking->events[0]->location);
        $this->assertSame(TrackStatus::OUT_FOR_DELIVERY, $tracking->events[1]->status);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->events[2]->status);
        $this->assertSame('Item delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
        $this->assertSame('2026-08-14T10:00:00', $tracking->deliveredAt->format('Y-m-d\TH:i:s'));
        $this->assertSame('delivered', $tracking->rawStatus);
    }

    public function testQueryTrackReversesDescendingEvents(): void
    {
        $body = json_encode([
            'parcels' => [
                [
                    'parcelId' => 'XX123456789CH',
                    'delivery' => ['status' => 'delivered'],
                    'events' => [
                        ['code' => 'ITEM_DELIVERED', 'status' => 'delivered', 'timestamp' => '2026-08-14T10:00:00+02:00', 'place' => 'Zurich', 'text' => 'Item delivered'],
                        ['code' => 'ITEM_POSTED', 'status' => 'in transit', 'timestamp' => '2026-08-08T09:00:00+02:00', 'place' => 'Bern', 'text' => 'Item posted'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $tracking = $this->makeAdapter($this->makeHttp($body))->queryTrack('XX123456789CH');

        $this->assertCount(2, $tracking->events);
        $this->assertSame('Item posted', $tracking->events[0]->description);
        $this->assertSame('Item delivered', $tracking->events[1]->description);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }

    public function testQueryTrackThrowsOnTokenAuthFailure(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('[SWISS-POST 401] OAuth token 获取失败');

        $this->makeAdapter($http)->queryTrack('XX123456789CH');
    }

    public function testQueryTrackThrowsOnBusinessError(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/swiss-post/error.json')));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SWISS-POST] Invalid parcel code');

        $adapter->queryTrack('XX123456789CH');
    }

    public function testQueryTrackThrowsWhenNoEvents(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp(file_get_contents(__DIR__ . '/../fixtures/swiss-post/empty.json')));

        $this->expectException(TrackingNotFoundException::class);

        $adapter->queryTrack('XX123456789CH');
    }

    public function testQueryTrackThrowsOnInvalidJson(): void
    {
        $adapter = $this->makeAdapter($this->makeHttp('not json at all'));

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('[SWISS-POST] 响应解析失败');

        $adapter->queryTrack('XX123456789CH');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = $this->makeAdapter(new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SWISS-POST createOrder 待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('XX123456789CH'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SWISS-POST createLabel 待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/hook');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('SWISS-POST subscribe 待实现', $e->getMessage());
        }
    }
}
