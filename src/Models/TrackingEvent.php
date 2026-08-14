<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

use GlobalLogistics\Support\TrackStatus;

final readonly class TrackingEvent
{
    public function __construct(
        public ?\DateTimeImmutable $occurredAt,
        public string $location,
        public string $description,
        public TrackStatus $status,
        public array $raw = [],
    ) {
    }
}
