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
 * Australia Post 国际件查询（Tracking API，AUSPOST-AUTH-APIKEY 请求头认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（事件结构、eventCode 取值、
 * 事件顺序均为公开文档推断）。
 * 文档: https://developers.auspost.com.au/apis/track
 */
final class AustraliaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://digitalapi.auspost.com.au/track/{trackingNo}';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), self::ENDPOINT), [
            'AUSPOST-AUTH-APIKEY' => (string) $this->config->get('australia-post.api_key'),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[AUSTRALIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[AUSTRALIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[AUSTRALIA-POST] 响应解析失败');
        }

        $tracking = $result['tracking'] ?? null;
        $events = [];
        if (is_array($tracking) && is_array($tracking['events'] ?? null)) {
            foreach ($tracking['events'] as $row) {
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

        return new Tracking(
            carrierCode: 'australia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['eventCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('australia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('australia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('australia-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dateTime'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            status: $this->mapStatus((string) ($row['description'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
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
        if (str_contains($text, 'collected') || str_contains($text, 'received') || str_contains($text, 'in transit')) {
            return TrackStatus::IN_TRANSIT;
        }

        return TrackStatus::UNKNOWN;
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
