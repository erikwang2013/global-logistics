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

    public function create(Channel $channel, string $carrierCode): CarrierInterface
    {
        $class = $this->registry[$channel->value][$carrierCode] ?? null;
        if ($class === null) {
            throw new CarrierNotFoundException(
                $carrierCode,
                $this->isDetectable($carrierCode)
                    ? sprintf('承运商 %s 已检测到但未注册（通道：%s）', $carrierCode, $channel->value)
                    : null,
            );
        }

        return new $class($this->config, $this->http);
    }

    /** 承运商代码是否命中默认检测规则（区分"未检测到"与"已检测到但未注册"） */
    private function isDetectable(string $carrierCode): bool
    {
        $rules = require __DIR__ . '/Resources/detector-rules.php';
        foreach ($rules as [, $code]) {
            if ($code === $carrierCode) {
                return true;
            }
        }

        return false;
    }
}
