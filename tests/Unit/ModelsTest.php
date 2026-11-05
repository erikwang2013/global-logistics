<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use PHPUnit\Framework\TestCase;

final class ModelsTest extends TestCase
{
    public function testTrackingEvent(): void
    {
        $event = new TrackingEvent(
            new \DateTimeImmutable('2026-08-14 10:00:00'),
            '深圳市',
            '快件已到达【深圳】',
            TrackStatus::IN_TRANSIT,
        );

        $this->assertSame('2026-08-14 10:00:00', $event->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('深圳市', $event->location);
        $this->assertSame('快件已到达【深圳】', $event->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $event->status);
    }

    public function testTracking(): void
    {
        $event = new TrackingEvent(null, '始发地', '已揽收', TrackStatus::PENDING);
        $tracking = new Tracking(
            'sf',
            'SF1234567890',
            TrackStatus::IN_TRANSIT,
            [$event],
            null,
            new \DateTimeImmutable('2026-08-15 18:00:00'),
            '快件已到达【深圳】',
            '运输中',
        );

        $this->assertSame('sf', $tracking->carrierCode);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertCount(1, $tracking->events);
        $this->assertSame('2026-08-15 18:00:00', $tracking->estimatedDeliveryAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已到达【深圳】', $tracking->latestDescription);
        $this->assertSame('运输中', $tracking->rawStatus);
    }

}
