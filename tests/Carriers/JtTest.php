<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class JtTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert(isset($body['sign']) && $body['sign'] !== '');
            assert(isset($body['timestamp']));
            assert($body['msg_type'] === 'GET_TRACES');
            assert($body['data']['tracking_number'] === 'JT1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/jt/track.json'));
        };

        $adapter = new Jt(
            new Config(['jt' => ['api_key' => 'test-api-key', 'secret' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('JT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('成都转运中心', $tracking->events[0]->location);
        $this->assertSame('2026-08-16 09:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }
}
