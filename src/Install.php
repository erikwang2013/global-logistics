<?php

declare(strict_types=1);

namespace GlobalLogistics;

/**
 * Webman 基础插件安装器（协议见 walkor/webman-framework src/support/Plugin.php）。
 * 安装/更新时把 config/logistics.php 拷贝为 {项目}/config/plugin/erikwang2013/global-logistics/app.php，
 * 卸载时删除该目录；用户经 config('plugin.erikwang2013.global-logistics.app') 读取。
 */
final class Install
{
    public const WEBMAN_PLUGIN = true;

    private const PLUGIN_CONFIG_DIR = 'config/plugin/erikwang2013/global-logistics';

    private const SOURCE_CONFIG = __DIR__ . '/../config/logistics.php';

    public static function install(bool $customize = false): void
    {
        self::copyConfig();
    }

    public static function update(): void
    {
        self::copyConfig();
    }

    public static function uninstall(): void
    {
        $dir = self::projectRoot() . '/' . self::PLUGIN_CONFIG_DIR;
        if (is_dir($dir)) {
            self::removeDir($dir);
        }
    }

    private static function copyConfig(): void
    {
        $dir = self::projectRoot() . '/' . self::PLUGIN_CONFIG_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('[global-logistics] 无法创建插件配置目录：%s', $dir));
        }
        copy(self::SOURCE_CONFIG, $dir . '/app.php');
    }

    /** composer 钩子运行时 webman helpers 已加载（base_path 可用）；PHPUnit 环境回退到 getcwd() */
    private static function projectRoot(): string
    {
        return function_exists('base_path') ? (string) base_path() : (string) getcwd();
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
