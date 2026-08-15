<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Install;
use PHPUnit\Framework\TestCase;

final class WebmanInstallTest extends TestCase
{
    private string $originalDir;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalDir = (string) getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/gl-webman-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot, 0777, true);
        chdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        self::removeDir($this->projectRoot);
    }

    public function testInstallCopiesConfigToPluginDir(): void
    {
        Install::install();

        $file = $this->pluginConfigFile();
        $this->assertFileExists($file);
        $config = require $file;
        $this->assertArrayHasKey('sf', $config);
        $this->assertArrayHasKey('dhl', $config);
        $this->assertSame(2, $config['max_retries']);
    }

    public function testUninstallRemovesPluginConfigDir(): void
    {
        Install::install();
        Install::uninstall();

        $this->assertDirectoryDoesNotExist($this->projectRoot . '/config/plugin/erikwang2013/global-logistics');
    }

    public function testUpdateKeepsConfigIntact(): void
    {
        Install::install();
        Install::update();

        $this->assertFileExists($this->pluginConfigFile());
    }

    private function pluginConfigFile(): string
    {
        return $this->projectRoot . '/config/plugin/erikwang2013/global-logistics/app.php';
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
