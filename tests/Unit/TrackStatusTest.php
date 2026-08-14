<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Support\TrackStatus;
use PHPUnit\Framework\TestCase;

final class TrackStatusTest extends TestCase
{
    public function testAllStatusesExist(): void
    {
        $this->assertSame(
            ['PENDING', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'EXCEPTION', 'RETURNED', 'UNKNOWN'],
            array_map(fn ($case) => $case->name, TrackStatus::cases())
        );
    }

    public function testFromValue(): void
    {
        $this->assertSame(TrackStatus::DELIVERED, TrackStatus::from('DELIVERED'));
    }
}
