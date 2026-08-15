<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Support;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
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
