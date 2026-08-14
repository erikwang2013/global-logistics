<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

use GlobalLogistics\Support\TrackStatus;

final readonly class Tracking
{
    /**
     * @param TrackingEvent[] $events
     */
    public function __construct(
        public string $carrierCode,
        public string $trackingNo,
        public TrackStatus $status,
        public array $events = [],
        public ?\DateTimeImmutable $deliveredAt = null,
        public ?\DateTimeImmutable $estimatedDeliveryAt = null,
        public string $latestDescription = '',
        public string $rawStatus = '',
        public array $raw = [],
    ) {
    }
}
