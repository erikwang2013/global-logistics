<?php

declare(strict_types=1);

namespace GlobalLogistics;

final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function all(): array
    {
        return $this->values;
    }
}
