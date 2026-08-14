<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SfTest extends TestCase
{
    private function makeAdapter(string $fixture): Sf
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($fixture) {
            $body = json_decode((string) $request->getBody(), true);

            assert(isset($body['partnerID']) && $body['partnerID'] === 'test-partner');
            assert(isset($body['msgDigest']) && is_string($body['msgDigest']) && $body['msgDigest'] !== '');
            assert(isset($body['serviceCode']) && $body['serviceCode'] === 'EXP_RECE_SEARCH');
            $msg = json_decode($body['msgData'], true);
            assert($msg['trackingNumber'] === 'SF1234567890');

            $content = file_get_contents(__DIR__ . '/../fixtures/sf/' . $fixture);

            return new Response(200, ['Content-Type' => 'application/json'], $content);
        };

        return new Sf(
            new Config(['sf' => ['partner_id' => 'test-partner', 'checkword' => 'test-checkword']]),
            $http,
        );
    }

    public function testQueryTrackParsesInTransit(): void
    {
        $tracking = $this->makeAdapter('track-in-transit.json')->queryTrack('SF1234567890');

        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('2026-08-14 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('深圳市南山区', $tracking->events[0]->location);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
    }

    public function testQueryTrackParsesDelivered(): void
    {
        $tracking = $this->makeAdapter('track-delivered.json')->queryTrack('SF1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertSame('2026-08-15 14:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：李四', $tracking->latestDescription);
    }

    public function testAuthErrorMapped(): void
    {
        $this->expectException(AuthException::class);
        $this->makeAdapter('error-invalid-key.json')->queryTrack('SF1234567890');
    }

    public function testSignatureVerification(): void
    {
        $sf = new Sf(new Config(['sf' => ['checkword' => 'test-checkword']]), new FakeHttpClient());
        $payload = '{"event":"push"}';
        $digest = base64_encode(md5($payload . 'test-checkword', true));

        $this->assertTrue($sf->verifyCallbackSignature($payload, $digest));
        $this->assertFalse($sf->verifyCallbackSignature($payload, 'wrong'));
    }
}
