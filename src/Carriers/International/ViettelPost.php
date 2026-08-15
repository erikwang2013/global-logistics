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
 * Viettel Post（越南邮政）国际件查询（官方网站公开跟踪接口，GET JSON）。
 *
 * VERIFIED-REQUIRED: 契约基于官方公开跟踪页（viettelpost.com.vn "Track your shipment"）
 * 接口形态推断，需实网验证（orderCode 查询参数与 result.data.tracking 事件数组的
 * time/location/description/status 字段为社区客户端推断，可能与实网略有出入）。
 * 文档: https://www.viettelpost.com.vn/
 */
final class ViettelPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.viettelpost.com.vn/CurrentTrackByOrderCode';

    /**
     * 事件文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含越南语）。
     * 'đang giao'（派送中）必须先于 'đã giao'（已投递）相关词。
     */
    private const STATUS_MAP = [
        'đang giao|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'đã giao|đã phát|giao thành công|delivered' => TrackStatus::DELIVERED,
        'hoàn|trả lại|returned|return' => TrackStatus::RETURNED,
        'thất bại|failed|exception' => TrackStatus::EXCEPTION,
        'đã nhận|nhận hàng|received|ready for pick up' => TrackStatus::PENDING,
        'vận chuyển|trung chuyển|transit|in transit' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('viettel-post.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?orderCode=' . rawurlencode($trackingNo), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[VIETTEL-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[VIETTEL-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[VIETTEL-POST] 响应解析失败');
        }

        $rawEvents = $result['result']['data']['tracking'] ?? $result['result']['data']['list'] ?? $result['tracking'] ?? [];
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
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'viettel-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('viettel-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('viettel-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('viettel-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? '');
        if ($description === '') {
            $description = (string) ($row['status_description'] ?? '');
        }
        if ($description === '') {
            $description = (string) ($row['content'] ?? '');
        }
        $location = (string) ($row['location'] ?? '');
        if ($location === '') {
            $location = (string) ($row['address'] ?? '');
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['time'] ?? ($row['last_updated_time'] ?? null)),
            location: $location,
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['status'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
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

        // 兼容 6 位微秒小数秒截断为 3 位毫秒
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
