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
 * Omniva（爱沙尼亚邮政）国际件查询（公开 tracking API，GET，无需认证，可选 lang 参数）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（端点 https://omniva.ee/api/tracking/
 * 的响应字段 events[].eventCode/eventDescription/eventDate 为文档推断；官方 OMX 业务 API
 * 文档（omx.omniva.eu）需认证且响应结构不同；事件描述为爱沙尼亚语/英语关键词：
 * delivered/kätte toimetatud、in transit/transiit、received/vastu võetud、
 * returned/tagastatud、exception）。
 * 文档: https://www.omniva.ee/en/business/api/documentation/
 */
final class Omniva implements CarrierInterface
{
    private const ENDPOINT = 'https://omniva.ee/api/tracking/';

    /**
     * eventDescription 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含爱沙尼亚语）。
     * 'out for delivery' 必须先于 'delivered'（包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|väljastamisel|väljastus' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|kätte toimetatud|toimetatud|kätte toimetamine' => TrackStatus::DELIVERED,
        'returned|return|tagastatud|tagastamine' => TrackStatus::RETURNED,
        'exception|error|viga|rikutud|cancelled|canceled' => TrackStatus::EXCEPTION,
        'received|vastu võetud|vastu voetud|registered|registreeritud|accepted|booked|picked up' => TrackStatus::PENDING,
        'in transit|in_transit|transit|transiit|saadetud|departed|arrived|sorted|forwarded|veos' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('omniva.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . rawurlencode($trackingNo) . '?lang=en', [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[OMNIVA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[OMNIVA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[OMNIVA] 响应解析失败');
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
            carrierCode: 'omniva',
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
        throw new LogisticsException('omniva createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('omniva createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('omniva subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? null),
            location: (string) ($row['eventLocation'] ?? ''),
            description: (string) ($row['eventDescription'] ?? ''),
            status: $this->mapStatus((string) ($row['eventDescription'] ?? '')),
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

        // 兼容 6 位微秒小数秒（如 2026-08-12T09:00:00.123456+03:00）截断为 3 位毫秒
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
