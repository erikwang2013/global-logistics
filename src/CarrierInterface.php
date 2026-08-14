<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;

interface CarrierInterface
{
    public function queryTrack(string $trackingNo, array $options = []): Tracking;

    public function createOrder(OrderRequest $request): Order;

    public function createLabel(Order $order, array $options = []): Label;

    public function subscribe(string $callbackUrl, array $options = []): void;
}
