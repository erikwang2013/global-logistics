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
 * Delhivery（印度）国际件查询（官方 Order Tracking API，GET + waybill 参数，Token 认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（认证方式官方文档为 URL 查询参数
 * token=XXX，本适配器同时以 Authorization: Token {key} 请求头发送以兼容两种形态；
 * 响应结构 ShipmentData[].Shipment.Scans[].ScanDetail（ScanDateTime/ScanType/Scan/
 * ScannedLocation/Status）与 StatusType 代码（DL/IN/OFD/RTO/EX/OTP/UD）以官方文档为准；
 * 业务错误响应体（status + message 字段）为推断形态）。
 * 文档: https://delhivery-express-api-doc.readme.io/reference/order-tracking-api
 */
final class Delhivery implements CarrierInterface
{
    private const ENDPOINT = 'https://track.delhivery.com/api/v1/packages/json/';

    /**
     * Status 描述文本 / ScanType 状态码关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|ofd' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|dl' => TrackStatus::DELIVERED,
        'returned|returning|rto|dto|rt' => TrackStatus::RETURNED,
        'undelivered|exception|cancelled|canceled|failed|rejected|held' => TrackStatus::EXCEPTION,
        'picked up|ready for pickup|out for pickup|manifested|booked|pending|created|intimated|otp|pp|pu' => TrackStatus::PENDING,
        'in transit|in_transit|transit|received|arrived|departed|sorted|forwarded|in ' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('delhivery.endpoint', self::ENDPOINT);
        $apiKey = (string) $this->config->get('delhivery.key', '');

        $params = ['waybill' => $trackingNo];
        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== '') {
            // 官方文档以 URL 查询参数 token= 认证；同时附 Authorization: Token 头兼容两种形态
            $params['token'] = $apiKey;
            $headers['Authorization'] = 'Token ' . $apiKey;
        }

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query($params), $headers);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[DELHIVERY %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[DELHIVERY %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DELHIVERY] 响应解析失败');
        }

        // 业务错误（HTTP 200 但响应体携带 status/message，如无效 waybill）
        if (isset($result['status']) && is_numeric($result['status']) && (int) $result['status'] >= 400) {
            throw new LogisticsException('[DELHIVERY] ' . (string) ($result['message'] ?? '接口错误'));
        }

        $events = [];
        $shipment = null;
        foreach (($result['ShipmentData'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $current = $item['Shipment'] ?? null;
            if (!is_array($current)) {
                continue;
            }
            $shipment = $current;
            foreach (($current['Scans'] ?? []) as $scan) {
                if (!is_array($scan)) {
                    continue;
                }
                // 官方结构每项包裹 ScanDetail；兼容平铺形态
                $row = is_array($scan['ScanDetail'] ?? null) ? $scan['ScanDetail'] : $scan;
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'delhivery',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($shipment['StatusType'] ?? ($shipment['Status'] ?? '')),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('delhivery createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('delhivery createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('delhivery subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $statusText = (string) ($row['Status'] ?? '');
        $scanText = (string) ($row['Scan'] ?? '');
        $scanType = (string) ($row['ScanType'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['ScanDateTime'] ?? null),
            location: (string) ($row['ScannedLocation'] ?? ''),
            description: $statusText !== '' ? $statusText : $scanText,
            status: $this->mapStatus($statusText . ' ' . $scanText . ' ' . $scanType),
            raw: $row,
        );
    }

    private function mapStatus(string $text): TrackStatus
    {
        $lower = strtolower($text);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($lower, $keyword)) {
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

        // 兼容 6 位微秒小数秒（如 2026-08-14T10:30:00.123456）截断为 3 位毫秒
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
