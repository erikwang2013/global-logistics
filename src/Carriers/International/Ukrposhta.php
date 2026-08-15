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
 * Ukrposhta（乌克兰邮政）国际件查询（官方 Status Tracking API，GET + Bearer token，
 * 支持 S10 UA 挂号（XX+9 位+UA）与 13 位数字条码）。
 *
 * VERIFIED-REQUIRED: 契约基于官方开发文档
 * https://dev.ukrposhta.ua/uploads/Status-tracking-API-15022024.pdf
 * （基址 https://www.ukrposhta.ua/status-tracking/0.0.1/statuses?barcode=...&lang=en，
 * Authorization: Bearer {uuid}，需与乌邮签署协议后获取；响应为状态对象数组，按 step 升序；
 * 未注册条码返回 404/未找到；event/eventName 为官方字段，41000=Delivery 等事件码为官方文档
 * 字段，中文状态文案需实网验证）。
 * 公开查询页: https://www.ukrposhta.ua/en/tracking
 */
final class Ukrposhta implements CarrierInterface
{
    private const ENDPOINT = 'https://www.ukrposhta.ua/status-tracking/0.0.1/statuses';

    /**
     * eventName/事件码关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，具体在前）。
     * 'доставлено'(已妥投) 必须先于 'достав'(派送词干)；事件码 41000=Delivery、
     * 41100=Not delivered、41200=Return 为官方文档事件码。
     */
    private const STATUS_MAP = [
        'delivered|вручено|вручення|доставлено|41000' => TrackStatus::DELIVERED,
        'out for delivery|out_for_delivery|доставка|видано|поштомат' => TrackStatus::OUT_FOR_DELIVERY,
        'return|поверненн|зворотн|41200' => TrackStatus::RETURNED,
        'exception|не вручено|не вручене|undeliver|відмов|помилк|41100' => TrackStatus::EXCEPTION,
        'acceptance|прийнято|зареєстровано|registered|accepted|10100' => TrackStatus::PENDING,
        'transit|arrival|arrived|shipment|depart|прямує|відправлено|прибуло|оброб' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('ukrposhta.endpoint', self::ENDPOINT);
        $apiKey = (string) $this->config->get('ukrposhta.api_key');

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'barcode' => $trackingNo,
            'lang' => (string) $this->config->get('ukrposhta.lang', 'en'),
        ]), [
            'Accept' => 'application/json',
            'Authorization' => sprintf('Bearer %s', $apiKey),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[UKRPOSHTA %d] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[UKRPOSHTA %d] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[UKRPOSHTA] 响应解析失败');
        }

        // 官方响应为状态对象数组，按 step 升序
        $events = [];
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ukrposhta',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result[count($result) - 1]['eventName'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('ukrposhta createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ukrposhta createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ukrposhta subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventName'] ?? '');
        if ($description === '') {
            $description = (string) ($row['eventReason'] ?? '');
        }
        $location = trim(sprintf('%s %s', (string) ($row['index'] ?? ''), (string) ($row['name'] ?? '')));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: $location,
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['event'] ?? '')),
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

    /** 支持 ISO8601 无时区（YYYY-MM-DDTHH:mm:ss）与 'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d H:i', '!Y-m-d'] as $format) {
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
