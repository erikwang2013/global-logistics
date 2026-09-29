<?php

declare(strict_types=1);

/*
 * Yii 3 集成：composer `extra.config-plugin` 把本文件并入 `params` 组（默认值层）。
 * 应用在自己的 config/common/params.php 中以同一键覆盖，yiisoft/config 递归合并：
 *
 *   return ['erikwang2013/global-logistics' => ['sf' => ['partner_id' => '...', 'checkword' => '...']]];
 *
 * 键 = 包名（Yii 3 约定），值 = `Logistics::configure()` 入参，默认即 config/logistics.php。
 */

return [
    'erikwang2013/global-logistics' => require __DIR__ . '/logistics.php',
];
