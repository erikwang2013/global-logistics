<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Channel;
use GlobalLogistics\Config;
use GlobalLogistics\Logistics;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
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
