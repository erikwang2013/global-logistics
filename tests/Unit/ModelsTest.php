<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
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

    public function testOrderRequestAndOrder(): void
    {
        $request = new OrderRequest(
            ['name' => '张三', 'phone' => '13800138000', 'address' => '深圳市南山区'],
            ['name' => '李四', 'phone' => '13900139000', 'address' => '北京市朝阳区'],
            ['weight' => 1.5, 'items' => [['name' => '书', 'qty' => 1]]],
        );
        $order = new Order('SF1234567890', 'TMS_LABEL', ['raw' => true]);

        $this->assertSame('张三', $request->sender['name']);
        $this->assertSame('SF1234567890', $order->trackingNo);
        $this->assertSame('TMS_LABEL', $order->labelContent);
        $this->assertSame(['raw' => true], $order->raw);
    }

    public function testLabel(): void
    {
        $label = new Label('pdf', 'JVBERi0xLjQ=', []);
        $this->assertSame('pdf', $label->format);
        $this->assertSame('JVBERi0xLjQ=', $label->content);
    }
}
