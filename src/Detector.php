<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Exceptions\CarrierNotFoundException;

final class Detector
{
    /**
     * @param array<string, array{0: string, 1: string}> $rules pattern => [channel, carrier]
     */
    public function __construct(private readonly array $rules = [])
    {
    }

    public static function withDefaults(): self
    {
        return new self(require __DIR__ . '/Resources/detector-rules.php');
    }

    public function detect(string $trackingNo): Detection
    {
        foreach ($this->rules as $pattern => [$channel, $carrier]) {
            if (preg_match($pattern, $trackingNo) === 1) {
                return new Detection(Channel::from($channel), $carrier);
            }
        }

        throw new CarrierNotFoundException($trackingNo);
    }
}
