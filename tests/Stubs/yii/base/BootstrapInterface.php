<?php

declare(strict_types=1);

namespace yii\base;

/**
 * 测试桩（仅本仓库 autoload-dev 生效）：真实 Yii 2 应用中由 yiisoft/yii2 提供同名接口，签名一致。
 * yiisoft/yii2 因 bower-asset 依赖无法在离线环境安装，故以桩替代验证集成逻辑。
 */
interface BootstrapInterface
{
    public function bootstrap($app);
}
