<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Channel;
use GlobalLogistics\Config;
use GlobalLogistics\Logistics;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class LogisticsTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    public function testConfigureAndDetect(): void
    {
        Logistics::configure(['http_client' => new FakeHttpClient()]);

        $result = Logistics::detect('SF1234567890');
        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sf', $result->carrierCode);
    }

    public function testTrackAutoDetectsAndReturnsTracking(): void
    {
        Logistics::configure(['http_client' => new FakeHttpClient(), 'registry' => [
            'domestic' => ['sf' => StubCarrier::class],
        ]]);

        $tracking = Logistics::track('SF1234567890');
        $this->assertSame('sf', $tracking->carrierCode);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
    }

    public function testDomesticExplicit(): void
    {
        Logistics::configure(['registry' => [
            'domestic' => ['sf' => StubCarrier::class],
        ]]);

        $this->assertInstanceOf(StubCarrier::class, Logistics::domestic('sf'));
    }

    public function testTrackRoutesToInternationalChannel(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => (string) $request->getUri() === 'https://api.dhl.com/mydhlapi/auth'
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dhl/track.json'));

        Logistics::configure([
            'http_client' => $http,
            'dhl' => ['client_id' => 'cid', 'client_secret' => 'cs'],
            'registry' => [
                'domestic' => [],
                'international' => ['dhl' => Dhl::class],
            ],
        ]);

        $tracking = Logistics::track('DHL1234567890');

        $this->assertSame('dhl', $tracking->carrierCode);
        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
    }
}

final class StubCarrier implements CarrierInterface
{
    public function __construct(public Config $config, public $http)
    {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        return new Tracking('sf', $trackingNo, TrackStatus::IN_TRANSIT);
    }

    public function createOrder(OrderRequest $request): Order
    {
        return new Order('SF1234567890');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        return new Label('pdf', '');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
    }
}
