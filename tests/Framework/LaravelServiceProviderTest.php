<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Framework\LaravelServiceProvider;
use GlobalLogistics\Logistics;
use GlobalLogistics\Tests\Support\FrameworkStubCarrier;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class LaravelServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    private function makeApp(array $logistics = []): Container
    {
        $app = new class extends Container {
            public bool $console = true;

            public function runningInConsole(): bool
            {
                return $this->console;
            }
        };
        $app->instance('config', new Repository(['logistics' => $logistics]));
        $app->instance('path.config', sys_get_temp_dir());

        return $app;
    }

    public function testRegisterMergesPackageDefaults(): void
    {
        $app = $this->makeApp(['sf' => ['partner_id' => 'u']]);
        $provider = new LaravelServiceProvider($app);
        $provider->register();

        $merged = $app['config']->get('logistics');
        $this->assertSame('u', $merged['sf']['partner_id']);
        $this->assertArrayHasKey('dhl', $merged);
        $this->assertSame(2, $merged['max_retries']);
    }

    public function testBootPublishesConfigAndConfiguresLogistics(): void
    {
        $app = $this->makeApp(['registry' => [
            'domestic' => ['sf' => FrameworkStubCarrier::class],
        ]]);
        $provider = new LaravelServiceProvider($app);
        $provider->register();
        $provider->boot();

        $prop = new \ReflectionProperty(\Illuminate\Support\ServiceProvider::class, 'publishes');
        $classPublishes = $prop->getValue()[LaravelServiceProvider::class] ?? [];
        // 键为 provider 内 __DIR__ 拼接的源配置路径（src/Framework/../../config/logistics.php）
        $matching = array_values(array_filter(
            array_keys($classPublishes),
            fn (string $key): bool => str_ends_with($key, '/config/logistics.php'),
        ));
        $this->assertNotEmpty($matching);
        $this->assertInstanceOf(FrameworkStubCarrier::class, Logistics::domestic('sf'));
    }

    public function testBootOutsideConsoleStillConfiguresLogistics(): void
    {
        $app = $this->makeApp(['registry' => [
            'domestic' => ['sf' => FrameworkStubCarrier::class],
        ]]);
        $app->console = false;
        $provider = new LaravelServiceProvider($app);
        $provider->register();
        $provider->boot();

        $this->assertInstanceOf(FrameworkStubCarrier::class, Logistics::domestic('sf'));
    }
}
