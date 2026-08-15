<?php

declare(strict_types=1);

namespace GlobalLogistics\Framework;

use GlobalLogistics\Logistics;
use think\Service;

/**
 * ThinkPHP 8 集成：composer extra.think.services 自动注册（vendor/services.php）。
 * 包内默认配置合并到 logistics 配置段（应用 config/logistics.php 优先）。
 */
final class ThinkService extends Service
{
    public function register(): void
    {
        $defaults = require __DIR__ . '/../../config/logistics.php';
        $user = $this->app->config->get('logistics', []);
        $this->app->config->set(array_replace_recursive($defaults, $user), 'logistics');
    }

    public function boot(): void
    {
        Logistics::configure((array) $this->app->config->get('logistics', []));
    }
}
