<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotNotationGet(): void
    {
        $config = new Config(['sf' => ['app_key' => 'abc', 'checkword' => 'xyz']]);

        $this->assertSame('abc', $config->get('sf.app_key'));
        $this->assertSame('xyz', $config->get('sf.checkword'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        $config = new Config([]);
        $this->assertNull($config->get('nope'));
        $this->assertSame('fallback', $config->get('nope', 'fallback'));
    }

    public function testAll(): void
    {
        $config = new Config(['a' => 1]);
        $this->assertSame(['a' => 1], $config->all());
    }
}
