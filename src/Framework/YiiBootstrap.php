<?php

declare(strict_types=1);

namespace GlobalLogistics\Framework;

use GlobalLogistics\Logistics;
use yii\base\BootstrapInterface;

/**
 * Yii 2 集成：composer type=yii2-extension + extra.bootstrap 自动注册。
 * 应用每次引导时从 params['logistics'] 读取配置并初始化 Logistics。
 */
final class YiiBootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        Logistics::configure((array) ($app->params['logistics'] ?? []));
    }
}
