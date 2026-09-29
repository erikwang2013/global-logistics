<?php

declare(strict_types=1);

use GlobalLogistics\Logistics;

/*
 * Yii 3 集成：composer `extra.config-plugin` 把本文件并入 `bootstrap` 组。
 * 应用启动时由 yii-runner 的 BootstrapRunner 逐个执行：$callback($container)。
 *
 * @var array<string, mixed> $params 由 yiisoft/config 注入本文件作用域，键为包名
 */

return [
    static function ($container) use ($params): void {
        Logistics::configure((array) ($params['erikwang2013/global-logistics'] ?? []));
    },
];
