<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class Order
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $trackingNo,
        public ?string $labelContent = null,
        public array $raw = [],
    ) {
    }
}
