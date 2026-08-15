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
 * PostNL 国际件查询（Shipment Status API v2，apikey 请求头认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（事件 code 为字母+数字
 * 组合时仅能靠描述推断状态，numeric code 1-4 映射为公开文档定义）。
 * 文档: https://developer.postnl.nl/browse-apis/send-and-track/shipment-api/
 */
final class Postnl implements CarrierInterface
{
    private const ENDPOINT = 'https://api.postnl.nl/shipment/v2/status/{trackingNo}';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), self::ENDPOINT), [
            'apikey' => (string) $this->config->get('postnl.api_key'),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POSTNL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POSTNL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POSTNL] 响应解析失败');
        }

        $statusRaw = $result['status'] ?? null;
        $events = [];
        if (is_array($result['events'] ?? null)) {
            foreach ($result['events'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        $trackingStatus = $latest->status;
        // 事件级状态未命中时回退到运单级 status.code（PostNL 1-4 定义）
        if ($trackingStatus === TrackStatus::UNKNOWN && is_array($statusRaw)) {
            $trackingStatus = $this->mapShipmentStatus($statusRaw);
        }

        return new Tracking(
            carrierCode: 'postnl',
            trackingNo: $trackingNo,
            status: $trackingStatus,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($statusRaw['code'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('postnl createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('postnl createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('postnl subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $locationRaw = $row['location'] ?? null;

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['timeStamp'] ?? null),
            location: is_array($locationRaw) ? (string) ($locationRaw['city'] ?? '') : '',
            description: (string) ($row['description'] ?? ''),
            status: $this->mapStatus($row['code'] ?? null, (string) ($row['description'] ?? '')),
            raw: $row,
        );
    }

    /** @param array<string, mixed> $statusRaw */
    private function mapShipmentStatus(array $statusRaw): TrackStatus
    {
        return $this->mapStatus($statusRaw['code'] ?? null, (string) ($statusRaw['description'] ?? ''));
    }

    private function mapStatus(mixed $code, string $description): TrackStatus
    {
        $text = strtolower($description);

        if (str_contains($text, 'delivered')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'out for delivery')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'returned')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'exception') || str_contains($text, 'failed')) {
            return TrackStatus::EXCEPTION;
        }

        // 事件 code 为字母+数字组合（如 A22）时 is_numeric 为 false，走 UNKNOWN；
        // 描述兜底：code 1 = 已交给承运商，2 = 分拣，3 = 投递中，4 = 已妥投
        return match (is_numeric($code) ? (int) $code : 0) {
            1 => TrackStatus::PENDING,
            2, 3 => TrackStatus::IN_TRANSIT,
            4 => TrackStatus::DELIVERED,
            default => TrackStatus::UNKNOWN,
        };
    }

    /**
     * 多格式时间解析：ISO8601 带时区偏移（含毫秒）、无偏移；全部失败返回 null。
     */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'] as $format) {
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
