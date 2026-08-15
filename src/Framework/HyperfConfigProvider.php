<?php

declare(strict_types=1);

namespace GlobalLogistics\Framework;

/**
 * Hyperf 集成：composer extra.hyperf.config 指向本类。
 * 运行 `php bin/hyperf.php vendor:publish` 发布配置到 config/autoload/logistics.php。
 */
final class HyperfConfigProvider
{
    public function __invoke(): array
    {
        return [
            'publish' => [
                [
                    'id' => 'logistics',
                    'description' => 'global-logistics 配置',
                    'source' => __DIR__ . '/../../config/logistics.php',
                    'destination' => BASE_PATH . '/config/autoload/logistics.php',
                ],
            ],
        ];
    }
}
