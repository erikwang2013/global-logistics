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
 * Posti（芬兰邮政）国际件查询（官方 Parcel Tracking API v2，POST JSON，公开模式无需认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（端点、请求体 trackingCode 字段名与
 * 响应 events[].eventDate/eventCode/eventDescription/eventState、parcelState 为文档推断；
 * 官方文档注明公开(public)模式按 tracking code 查询任意包裹、normal 模式需 OAuth2 凭据，
 * 事件描述为芬兰语/英语关键词：delivered/perillä、in transit/kuljetuksessa、
 * out for delivery/jakelussa、returned/palautettu、waiting for pick up/noudettavissa）。
 * 文档: https://api.posti.fi/api-tracking.html
 */
final class Posti implements CarrierInterface
{
    private const ENDPOINT = 'https://www.posti.fi/api/parceltracking/v2/parcelTracking';

    /**
     * eventDescription/eventState 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含芬兰语）。
     * 'out for delivery' 必须先于 'delivered'（包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|jakelussa|jakeluun' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|perillä|toimitettu' => TrackStatus::DELIVERED,
        'returned|return|palautettu|palautus' => TrackStatus::RETURNED,
        'exception|poikkeus|vika|cancelled|canceled|failed' => TrackStatus::EXCEPTION,
        'waiting for pick up|noudettavissa|ready for pick up|ready for pickup|avised' => TrackStatus::PENDING,
        'in transit|in_transit|transit|kuljetuksessa|received|vastaanotettu|arrived|departed|sorted|forwarded|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('posti.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], (string) json_encode([
            'trackingCode' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POSTI %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POSTI %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POSTI] 响应解析失败');
        }

        $rawEvents = $result['events'] ?? [];
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
            carrierCode: 'posti',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['parcelState'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('posti createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('posti createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('posti subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');
        $eventState = (string) ($row['eventState'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? null),
            location: (string) ($row['eventLocation'] ?? ''),
            description: $description,
            status: $this->mapStatus($description . ' ' . $eventState),
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

        // 兼容 6 位微秒小数秒（如 2026-08-14T12:15:00.123456+03:00）截断为 3 位毫秒
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
