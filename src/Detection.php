<?php

declare(strict_types=1);

namespace GlobalLogistics;

final readonly class Detection
{
    public function __construct(
        public Channel $channel,
        public string $carrierCode,
    ) {
    }
}
