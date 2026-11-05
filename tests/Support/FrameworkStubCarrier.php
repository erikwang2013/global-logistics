<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Support;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Support\TrackStatus;

final class FrameworkStubCarrier implements CarrierInterface
{
    public function __construct(public Config $config, public mixed $http)
    {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        return new Tracking('sf', $trackingNo, TrackStatus::IN_TRANSIT);
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
    }
}
