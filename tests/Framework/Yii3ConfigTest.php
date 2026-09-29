<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Channel;
use GlobalLogistics\Logistics;
use GlobalLogistics\Tests\Support\FrameworkStubCarrier;
use PHPUnit\Framework\TestCase;

/**
 * Yii 3 集成：composer extra.config-plugin 把 config/params.php 并入 params 组、
 * config/bootstrap.php 并入 bootstrap 组，由 yiisoft/config 合并、yii-runner 的 BootstrapRunner 执行。
 */
final class Yii3ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    public function testComposerDeclaresConfigPluginGroups(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('config', $composer['extra']['config-plugin-options']['source-directory']);
        $this->assertSame('params.php', $composer['extra']['config-plugin']['params']);
        $this->assertSame('bootstrap.php', $composer['extra']['config-plugin']['bootstrap']);

        foreach ($composer['extra']['config-plugin'] as $file) {
            $this->assertFileExists(__DIR__ . '/../../config/' . $file);
        }
    }

    public function testParamsFileProvidesPackageDefaults(): void
    {
        $params = require __DIR__ . '/../../config/params.php';

        $logistics = $params['erikwang2013/global-logistics'];
        $this->assertSame(['partner_id' => '', 'checkword' => ''], $logistics['sf']);
        $this->assertSame(2, $logistics['max_retries']);
    }

    public function testBootstrapGroupConfiguresLogisticsFromParams(): void
    {
        $bootstrap = $this->loadBootstrap([
            'erikwang2013/global-logistics' => [
                'registry' => ['domestic' => ['sf' => FrameworkStubCarrier::class]],
            ],
        ]);

        $this->assertCount(1, $bootstrap);
        $this->assertIsCallable($bootstrap[0]);

        $bootstrap[0](null); // BootstrapRunner::run() 把容器传给回调，本包静态门面不用容器

        $this->assertInstanceOf(FrameworkStubCarrier::class, Logistics::domestic('sf'));
    }

    public function testBootstrapGroupWithoutPackageParamsDoesNotThrow(): void
    {
        $bootstrap = $this->loadBootstrap([]);
        $bootstrap[0](null);

        $this->assertSame(Channel::Domestic, Logistics::detect('SF1234567890')->channel);
    }

    /**
     * 复刻 yiisoft\config\Config::buildFile()：`$params` 注入到配置文件的文件作用域后才 require。
     *
     * @param array<string, mixed> $params
     * @return array<int, callable>
     */
    private function loadBootstrap(array $params): array
    {
        return (static function (string $file) use ($params): array {
            /** @var array<int, callable> $list */
            $list = require $file;
            return $list;
        })(__DIR__ . '/../../config/bootstrap.php');
    }
}
