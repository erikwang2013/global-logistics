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

    public function testDetectsYunda(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('YD12345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('yd', $result->carrierCode);
    }

    public function testDetectsShentongBeforeZtoRule(): void
    {
        // 77 开头 13 位数字：/^77\d{11}$/ 必须先于 /^\d{13}$/ 命中，判定为 sto 而非 zto
        $detector = Detector::withDefaults();
        $result = $detector->detect('7730012345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sto', $result->carrierCode);
    }

    public function testDetectsJd(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('JD1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('jd', $result->carrierCode);
    }

    public function testDetectsEmsBeforeGenericFedExRule(): void
    {
        // EA...CN 同时匹配通用 FedEx 规则（/^[A-Z]{2}\d{9}[A-Z]{2}$/i），EMS 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('EA123456789CN');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ems', $result->carrierCode);
    }

    public function testDetectsFedExPrefix(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('FEDEX1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('fedex', $result->carrierCode);
    }

    public function testDetectsUps(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('1Z9999999999999999');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ups', $result->carrierCode);
    }

    public function testDetectsUsps(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('9400111899223197448523');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('usps', $result->carrierCode);
    }
}
