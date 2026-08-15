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
 * PostNord（瑞典/丹麦邮政）国际件查询（官方 Track & Trace API v5，GET + apikey，
 * 支持 SE/DK 两种 S10 尾码）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（apikey 需在
 * developer.postnord.com 注册后获取；文档示例 atapi2 为测试环境，生产为 api2；
 * shipments[].items[].events 按时间降序返回（最新在前）；未找到单号返回
 * HTTP 404 或空 shipments；statusCode 如 FINAL_DELIVERED 为官方字段，
 * location.displayName 为官方字段）。
 * 文档: https://developer.postnord.com/apis/details?systemName=shipment-v5-trackandtrace-shipmentinformation
 */
final class PostNord implements CarrierInterface
{
    private const ENDPOINT = 'https://api2.postnord.com/rest/shipment/v5/trackandtrace/findByIdentifier.json';

    /**
     * eventDescription/statusCode 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out_for_delivery|utlämning' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|levererad|utdelad' => TrackStatus::DELIVERED,
        'returned|return|retur' => TrackStatus::RETURNED,
        'failed|exception|held|unclaimed|missing|abandoned|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available for pickup|collected|picked up|waiting|aviserad|finns att hämta' => TrackStatus::PENDING,
        'in transit|in_transit|transit|received|arrived|departed|sorted|dispatched|accepted|handed|shipped|created|booking|registered|in transport|i transport|inlevererad' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('postnord.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'identifier' => $trackingNo,
            'locale' => (string) $this->config->get('postnord.locale', 'en'),
            'apikey' => (string) $this->config->get('postnord.api_key'),
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POSTNORD %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POSTNORD %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POSTNORD] 响应解析失败');
        }

        $shipment = $result['TrackingInformationResponse']['shipments'][0] ?? null;
        if (!is_array($shipment)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($shipment['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['events'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'postnord',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($shipment['statusCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('postnord createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('postnord createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('postnord subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');
        if ($description === '') {
            $description = (string) ($row['status'] ?? $row['eventCode'] ?? '');
        }
        $location = $row['location'] ?? null;
        $locationName = '';
        if (is_array($location)) {
            $locationName = (string) ($location['displayName'] ?? $location['city'] ?? '');
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventTime'] ?? null),
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
