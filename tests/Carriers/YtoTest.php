<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class YtoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            assert($query['app_key'] === 'test-app-key');
            assert(isset($query['sign']) && $query['sign'] !== '');
            $body = json_decode((string) $request->getBody(), true);
            assert($body['trackingNumber'] === 'YT1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yto/track.json'));
        };

        $adapter = new Yto(
            new Config(['yto' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('签收成功', $tracking->latestDescription);
    }
}
