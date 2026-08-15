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
 * Canada Post 国际件查询（Tracking API，REST + HTTP Basic 认证，XML 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（Basic 头使用
 * customerNumber:apiKey、occurrence 事件结构均为公开文档推断）。
 * 文档: https://www.canadapost-postescanada.ca/devnet/api-ref/track/vis/trackpin-1_0.html
 */
final class CanadaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://soa-gw.canadapost.ca/vis/track/pin/{trackingNo}/details';

    /** Canada Post event-identifier => 统一状态（M0/M1/M2/M5 为公开文档定义） */
    private const CODE_MAP = [
        'M0' => TrackStatus::DELIVERED,
        'M1' => TrackStatus::IN_TRANSIT,
        'M2' => TrackStatus::OUT_FOR_DELIVERY,
        'M5' => TrackStatus::EXCEPTION,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), self::ENDPOINT), [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config->get('canada-post.customer_number') . ':' . (string) $this->config->get('canada-post.api_key'),
            ),
            'Accept' => 'application/vnd.cpc.track+xml',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CANADA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CANADA-POST %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[CANADA-POST] 响应解析失败');
        }

        $occurrences = $parsed->{'significant-events'}->occurrence ?? null;
        if (isset($parsed->error) || $occurrences === null || count($occurrences) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($occurrences as $occurrence) {
            $events[] = $this->mapEvent($occurrence);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'canada-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['event_identifier'] ?? ''),
            raw: ['significant_events' => $this->simpleXmlToArray($occurrences)],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('canada-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('canada-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('canada-post subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $occurrence): TrackingEvent
    {
        $identifier = (string) ($occurrence->{'event-identifier'} ?? '');
        $description = (string) ($occurrence->{'event-description'} ?? '');
        $date = (string) ($occurrence->{'event-date'} ?? '');
        $time = (string) ($occurrence->{'event-time'} ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseEventTime($date, $time),
            location: (string) ($occurrence->site ?? ''),
            description: $description,
            status: $this->mapStatus($identifier, $description),
            raw: [
                'event_identifier' => $identifier,
                'event_description' => $description,
                'event_date' => $date,
                'event_time' => $time,
                'site' => (string) ($occurrence->site ?? ''),
            ],
        );
    }

    private function mapStatus(string $identifier, string $description): TrackStatus
    {
        if (isset(self::CODE_MAP[$identifier])) {
            return self::CODE_MAP[$identifier];
        }
        if (str_contains(strtolower($description), 'return')) {
            return TrackStatus::RETURNED;
        }

        return TrackStatus::UNKNOWN;
    }

    /** event-date 与 event-time 分开返回（如 "2026-08-14" + "10:00:00"） */
    private function parseEventTime(string $date, string $time): ?\DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($date . ' ' . $time));

        return $dt === false ? null : $dt;
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

    /** @return array<int, array<string, string>> */
    private function simpleXmlToArray(\SimpleXMLElement $occurrences): array
    {
        $rows = [];
        foreach ($occurrences as $occurrence) {
            $rows[] = [
                'event_identifier' => (string) ($occurrence->{'event-identifier'} ?? ''),
                'event_description' => (string) ($occurrence->{'event-description'} ?? ''),
                'event_date' => (string) ($occurrence->{'event-date'} ?? ''),
                'event_time' => (string) ($occurrence->{'event-time'} ?? ''),
                'site' => (string) ($occurrence->site ?? ''),
            ];
        }

        return $rows;
    }
}
