<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ZtoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert($body['companyId'] === 'test-company');
            assert($body['msgType'] === 'TRACK');
            assert(isset($body['dataDigest']) && $body['dataDigest'] !== '');
            assert(json_decode($body['data'], true)['billNo'] === 'ZTO1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/zto/track.json'));
        };

        $adapter = new Zto(
            new Config(['zto' => ['company_id' => 'test-company', 'secret' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('ZTO1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('【广州市】快件已到达【广州转运中心】', $tracking->events[0]->description);
        $this->assertSame('2026-08-15 16:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }
}
