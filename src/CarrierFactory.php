<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Exceptions\CarrierNotFoundException;
use Psr\Http\Client\ClientInterface;

final class CarrierFactory
{
    /**
     * @param array<string, array<string, class-string<CarrierInterface>>> $registry
     */
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
        private readonly array $registry = [],
    ) {
    }

    public static function withDefaults(Config $config, ClientInterface $http): self
    {
        return new self($config, $http, require __DIR__ . '/Resources/carrier-registry.php');
    }

    public function create(Channel $channel, string $carrierCode): CarrierInterface
    {
        $class = $this->registry[$channel->value][$carrierCode] ?? null;
        if ($class === null) {
            throw new CarrierNotFoundException($carrierCode);
        }

        return new $class($this->config, $this->http);
    }
}
