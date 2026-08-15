<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

/**
 * InPost（波兰包裹柜）国际件查询（官方 ShipX PL Tracking API，GET，无需认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（tracking_details 按官方文档为
 * 降序返回、字段为 status/origin_status/agency/datetime（任务描述的 status_description/
 * occurred_at 为推断别名，本适配器两者兼容读取）；status 事件码如 DOR/UWP/PDD_2 与
 * 顶层 status（如 delivered）为官方字段；无匹配单号返回 HTTP 404 + resource_not_found）。
 * 文档: https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/18153479/1.7.3+Shipment+Tracking
 */
final class InPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api-shipx-pl.easypack24.net/v1/tracking/';

    /**
     * origin_status/status_description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out_for_delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered' => TrackStatus::DELIVERED,
        'returned|return' => TrackStatus::RETURNED,
        'exception|not handed|not_handed|failure|failed|incorrect|damaged' => TrackStatus::EXCEPTION,
        'ready to pick up|ready_to_pickup|waiting for pick up|waiting_for_pickup|avised|available|to be collected' => TrackStatus::PENDING,
        'in transit|in_transit|transit|confirmed|dispatched|adopted|sent|received|picked up|picked_up|collected|created|prepared|booked' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('inpost.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . rawurlencode($trackingNo), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[INPOST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[INPOST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[INPOST] 响应解析失败');
        }

        $rawEvents = $result['tracking_details'] ?? [];
        if (!is_array($rawEvents) || $rawEvents === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($rawEvents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'inpost',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('inpost createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('inpost createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('inpost subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $originStatus = (string) ($row['origin_status'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $description = (string) ($row['status_description'] ?? '');
        if ($description === '') {
            $description = $originStatus !== '' ? $originStatus : $status;
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['datetime'] ?? ($row['occurred_at'] ?? null)),
            location: (string) ($row['agency'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $trackStatus) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $trackStatus;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒（如 2023-12-18T11:20:06.123456+01:00）截断为 3 位毫秒
        if (preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /**
     * 事件保持升序、末条为最新；承运商返回降序时反转（时间不可解析时保持原始顺序）。
     *
     * @param TrackingEvent[] $events
     * @return TrackingEvent[]
     */
    private function ensureAscending(array $events): array
    {
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            return array_reverse($events);
        }

        return $events;
    }
}
