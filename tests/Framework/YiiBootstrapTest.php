<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Framework;

use GlobalLogistics\Channel;
use GlobalLogistics\Framework\YiiBootstrap;
use GlobalLogistics\Logistics;
use GlobalLogistics\Tests\Support\FrameworkStubCarrier;
use PHPUnit\Framework\TestCase;

final class YiiBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    public function testBootstrapConfiguresFromParams(): void
    {
        $app = (object) ['params' => [
            'logistics' => [
                'registry' => ['domestic' => ['sf' => FrameworkStubCarrier::class]],
            ],
        ]];
        $bootstrap = new YiiBootstrap();
        $bootstrap->bootstrap($app);

        $this->assertInstanceOf(FrameworkStubCarrier::class, Logistics::domestic('sf'));
    }

    public function testBootstrapWithoutParamsDoesNotThrow(): void
    {
        $bootstrap = new YiiBootstrap();
        $bootstrap->bootstrap((object) ['params' => []]);

        $this->assertSame(Channel::Domestic, Logistics::detect('SF1234567890')->channel);
    }
}
