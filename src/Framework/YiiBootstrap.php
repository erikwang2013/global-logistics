<?php

declare(strict_types=1);

namespace GlobalLogistics\Framework;

use GlobalLogistics\Logistics;
use yii\base\BootstrapInterface;

/**
 * Yii 2 集成：composer type=yii2-extension + extra.bootstrap 自动注册。
 * 应用每次引导时从 params['logistics'] 读取配置并初始化 Logistics。
 *
 * Yii 3 不走本类（其应用无 params 对象/引导类概念），改用 config/params.php + config/bootstrap.php
 * 经 yiisoft/config 的 params / bootstrap 两组接入，详见 README「框架集成 - Yii 3」。
 */
final class YiiBootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        Logistics::configure((array) ($app->params['logistics'] ?? []));
    }
}
