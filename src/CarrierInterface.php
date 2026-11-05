<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Models\Tracking;

interface CarrierInterface
{
    public function queryTrack(string $trackingNo, array $options = []): Tracking;

    public function subscribe(string $callbackUrl, array $options = []): void;
}
