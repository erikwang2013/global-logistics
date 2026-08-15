<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Framework\ThinkService;
use GlobalLogistics\Logistics;
use GlobalLogistics\Tests\Support\FrameworkStubCarrier;
use PHPUnit\Framework\TestCase;
use think\App;

final class ThinkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    private function makeApp(): App
    {
        return new App(sys_get_temp_dir() . '/gl-tp-' . bin2hex(random_bytes(4)));
    }

    public function testRegisterMergesPackageDefaultsWithUserOverrides(): void
    {
        $app = $this->makeApp();
        $service = new ThinkService($app);

        // 模拟应用 config/logistics.php 已加载（用户覆盖）
        $app->config->set(['sf' => ['partner_id' => 'u']], 'logistics');
        $service->register();

        $merged = $app->config->get('logistics');
        $this->assertSame('u', $merged['sf']['partner_id']);
        $this->assertArrayHasKey('dhl', $merged);
        $this->assertSame(2, $merged['max_retries']);
    }

    public function testBootConfiguresLogistics(): void
    {
        $app = $this->makeApp();
        $service = new ThinkService($app);
        $service->register();
        $app->config->set(['registry' => [
            'domestic' => ['sf' => FrameworkStubCarrier::class],
        ]], 'logistics');
        $service->boot();

        $this->assertInstanceOf(FrameworkStubCarrier::class, Logistics::domestic('sf'));
    }
}
