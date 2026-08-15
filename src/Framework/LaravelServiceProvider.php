<?php

declare(strict_types=1);

namespace GlobalLogistics\Framework;

use GlobalLogistics\Logistics;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel 集成：composer extra.laravel.providers 自动发现。
 * 包内默认配置合并到 config('logistics')；`php artisan vendor:publish --tag=global-logistics` 发布配置模板。
 */
final class LaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/logistics.php', 'logistics');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/logistics.php' => config_path('logistics.php'),
            ], 'global-logistics');
        }

        Logistics::configure((array) ($this->app['config']->get('logistics') ?? []));
    }
}
