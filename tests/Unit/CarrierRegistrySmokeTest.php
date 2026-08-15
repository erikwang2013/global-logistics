<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\CarrierFactory;
use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Channel;
use GlobalLogistics\Config;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CarrierRegistrySmokeTest extends TestCase
{
    public function testAllRegisteredCarriersInstantiate(): void
    {
        $registry = require __DIR__ . '/../../src/Resources/carrier-registry.php';
        $factory = new CarrierFactory(new Config([]), new FakeHttpClient(), $registry);

        $count = 0;
        foreach ($registry as $channel => $codes) {
            foreach ($codes as $code => $class) {
                $adapter = $factory->create(Channel::from($channel), $code);
                $this->assertInstanceOf(CarrierInterface::class, $adapter);
                $this->assertInstanceOf($class, $adapter);
                $count++;
            }
        }

        $this->assertSame(132, $count);
    }
}
