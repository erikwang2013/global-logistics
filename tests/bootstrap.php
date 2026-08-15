<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// config_path() 仅在完整 Laravel 应用中定义；服务提供者单测的兜底实现。
if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir() . '/' . $path;
    }
}
