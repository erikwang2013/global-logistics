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
 * Egypt Post（埃及邮政）国际件查询（官方 TrackTrace GetShipmentDetails 端点，
 * GET + Barcode 参数，无认证）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 TrackTrace 页面公开逆向资料（shipbridge-egyptpost
 * 开源驱动：GetShipmentDetails?Barcode={barcode}；响应或为 JSON 或为 HTML 内嵌
 * JSON，本适配器按 JSON 解析；事件列表键 Events/History/TrackInfo，事件字段
 * Description/Date/Location，总状态键 Status/CurrentStatus/ShipmentStatus 均为该
 * 驱动容错清单，需实网验证）；单号无效时返回空事件或错误状态，按无事件处理；
 * 状态关键词为官网标准（Delivered/Out for Delivery/Received at 等）。
 * 文档: https://egyptpost.gov.eg/ar-eg/TrackTrace
 */
final class EgyptPost implements CarrierInterface
{
    private const ENDPOINT = 'https://egyptpost.gov.eg/ar-eg/TrackTrace/GetShipmentDetails';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含阿拉伯语）。
     * 'out for delivery' 须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|خروج للتسليم' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|تم التسليم|سلمت' => TrackStatus::DELIVERED,
        'returned|return to sender|مرتجع' => TrackStatus::RETURNED,
        'undeliver|failed|held|refused|unclaimed|exception|رفض|فشل' => TrackStatus::EXCEPTION,
        'accepted|received|created|deposited|ready for pickup|picked up|استلام' => TrackStatus::PENDING,
        'in transit|transit|arrived|departed|sorted|dispatched|forwarded|presented|customs|processed|في الطريق|قدمت' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('egypt-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'Barcode' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[EGYPT-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[EGYPT-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[EGYPT-POST] 响应解析失败');
        }

        $rawStatus = (string) ($result['Status'] ?? $result['CurrentStatus'] ?? $result['ShipmentStatus'] ?? '');
        $rawEvents = $result['Events'] ?? $result['History'] ?? $result['TrackInfo'] ?? null;
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
            carrierCode: 'egypt-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $rawStatus,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('egypt-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('egypt-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('egypt-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['Description'] ?? $row['EventDescription'] ?? $row['Status'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['Date'] ?? $row['EventDate'] ?? $row['Time'] ?? null),
            location: (string) ($row['Location'] ?? $row['EventLocation'] ?? $row['Office'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
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

    /** 支持 ISO8601、'Y-m-d H:i:s'、'd/m/Y H:i:s' 等，解析失败返回 null */
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

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y',
        ] as $format) {
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
