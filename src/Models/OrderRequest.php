<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class OrderRequest
{
    /**
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $receiver
     * @param array<string, mixed> $package
     * @param array<string, mixed> $options
     */
    public function __construct(
        public array $sender,
        public array $receiver,
        public array $package = [],
        public array $options = [],
    ) {
    }
}
