<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Channel;
use GlobalLogistics\Detector;
use GlobalLogistics\Exceptions\CarrierNotFoundException;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    public function testDetectsSfDomestic(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('SF1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sf', $result->carrierCode);
    }

    public function testDetectsDhlInternational(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DHL1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('dhl', $result->carrierCode);
    }

    public function testUnknownThrows(): void
    {
        $detector = Detector::withDefaults();

        $this->expectException(CarrierNotFoundException::class);
        $detector->detect('ZZZ99999999');
    }

    public function testCustomRules(): void
    {
        $detector = new Detector([
            '/^AB\d{8}$/' => ['domestic', 'ab'],
        ]);
        $result = $detector->detect('AB12345678');

        $this->assertSame('ab', $result->carrierCode);
    }
}
