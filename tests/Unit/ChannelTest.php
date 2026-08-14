<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Channel;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    public function testDomesticValue(): void
    {
        $this->assertSame('domestic', Channel::Domestic->value);
    }

    public function testInternationalValue(): void
    {
        $this->assertSame('international', Channel::International->value);
    }

    public function testFromValue(): void
    {
        $this->assertSame(Channel::Domestic, Channel::from('domestic'));
        $this->assertSame(Channel::International, Channel::from('international'));
    }
}
