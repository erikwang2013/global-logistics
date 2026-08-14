<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class Label
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $format,
        public string $content,
        public array $raw = [],
    ) {
    }
}
