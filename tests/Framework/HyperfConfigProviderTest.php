<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Framework\HyperfConfigProvider;
use PHPUnit\Framework\TestCase;

final class HyperfConfigProviderTest extends TestCase
{
    protected function setUp(): void
    {
        // Hyperf 应用恒定义 BASE_PATH；测试环境兜底
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir());
        }
    }

    public function testInvokeReturnsPublishEntry(): void
    {
        $result = (new HyperfConfigProvider())();

        $this->assertArrayHasKey('publish', $result);
        $entry = $result['publish'][0];
        $this->assertSame('logistics', $entry['id']);
        $this->assertFileExists($entry['source']);
        $this->assertSame(BASE_PATH . '/config/autoload/logistics.php', $entry['destination']);
    }
}
