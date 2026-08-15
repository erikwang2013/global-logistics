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
 * Deutsche Post（德国邮政）国际件查询（官方 DHL Shipment Tracking API，GET +
 * DHL-API-Key，service=de-post 覆盖德国邮政国际 S10 件）。
 *
 * VERIFIED-REQUIRED: 契约基于 DHL Shipment Tracking API 官方文档，需实网验证
 * （DHL-API-Key 需在 developer.dhl.com 注册后获取；service=de-post 为官方文档
 * 支持的服务代码；events[].timestamp/description/statusCode 与
 * location.address.addressLocality 为官方字段；statusCode 如 delivered/transit/
 * out-for-delivery/failure/on-hold/returned 为官方字段；单号无效时返回空
 * shipments 数组；事件按时间升序返回）。
 * 文档: https://developer.dhl.com/api-reference/shipment-tracking
 */
final class DeutschePost implements CarrierInterface
{
    private const ENDPOINT = 'https://api-eu.dhl.com/track/shipments';

    /**
     * 事件描述/statusCode 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out-for-delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|delivery completed' => TrackStatus::DELIVERED,
        'returned|return to sender|returned to sender' => TrackStatus::RETURNED,
        'failure|failed|on hold|on-hold|exception|held|undeliver|refused' => TrackStatus::EXCEPTION,
        'picked up|pickup|ready for pickup|available for pickup|collected' => TrackStatus::PENDING,
        'transit|in transit|in-transit|received|arrived|departed|sorted|dispatched|accepted|processed|registered|created|shipment created|unknown' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('deutsche-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
            'service' => 'de-post',
            'language' => 'en',
        ]), [
            'Accept' => 'application/json',
            'DHL-API-Key' => (string) $this->config->get('deutsche-post.api_key'),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[DEUTSCHE-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[DEUTSCHE-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DEUTSCHE-POST] 响应解析失败');
        }

        $shipment = $result['shipments'][0] ?? null;
        if (!is_array($shipment)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($shipment['events'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方按时间升序返回；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'deutsche-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($shipment['status']['statusCode'] ?? $shipment['statusCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('deutsche-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('deutsche-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('deutsche-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? $row['status'] ?? $row['statusCode'] ?? '');
        $location = $row['location'] ?? null;
        $locationName = '';
        if (is_array($location)) {
            $address = $location['address'] ?? null;
            if (is_array($address)) {
                $locationName = (string) ($address['addressLocality'] ?? $address['city'] ?? '');
            } else {
                $locationName = (string) ($location['addressLocality'] ?? $location['city'] ?? '');
            }
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['timestamp'] ?? null),
            location: $locationName,
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['statusCode'] ?? '')),
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

    /** 支持 ISO8601 带时区（含毫秒）、'Y-m-d H:i:s' 等，解析失败返回 null */
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

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', '!Y-m-d'] as $format) {
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