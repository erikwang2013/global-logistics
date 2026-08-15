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
 * Japan Post（日本邮政 EMS）国际件查询（公开追踪页 direct 接口，纯 GET + XML 响应，无 OAuth）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证
 * 文档: https://trackings.post.japanpost.jp/services/srv/search/direct
 */
final class JapanPost implements CarrierInterface
{
    private const ENDPOINT = 'https://trackings.post.japanpost.jp/services/srv/search/direct';

    /**
     * statusName 关键词 => 统一状态。
     * 'OUT OF DELIVERY' 必须先于 'DELIVERY'（"Out of delivery" 包含 "Delivery"）；
     * 关键词大小写不敏感；'お届け'（日语"送达"）按原文匹配。
     */
    private const STATUS_MAP = [
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'HELD' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'OUT OF DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'DELIVERY' => TrackStatus::DELIVERED,
        'DELIVERED' => TrackStatus::DELIVERED,
        'お届け' => TrackStatus::DELIVERED,
        'ACCEPTANCE' => TrackStatus::PENDING,
        'POSTING' => TrackStatus::PENDING,
        'ARRIVAL' => TrackStatus::IN_TRANSIT,
        'DEPARTURE' => TrackStatus::IN_TRANSIT,
        'IN TRANSIT' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config, // kept for uniform constructor shape (factory passes config + http); direct 接口无需签名，user_id 预留
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'requestNo1' => $trackingNo,
            'locale' => 'en',
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[JAPAN-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[JAPAN-POST %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[JAPAN-POST] 响应解析失败');
        }

        $result = $parsed->result ?? null;
        if ($result === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        $rawRecords = [];
        foreach (($result->records?->record ?? []) as $record) {
            $raw = (array) $record;
            $rawRecords[] = $raw;
            $event = $this->mapEvent($raw);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 日本邮政记录按时间降序（最新在前），统一反转为升序，末条为最新
        $events = self::sortEvents($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'japan-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['statusName'] ?? ''),
            raw: ['records' => $rawRecords],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('japan-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('japan-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('japan-post subscribe 待实现');
    }

    /** @param array<string, mixed> $raw */
    private function mapEvent(array $raw): ?TrackingEvent
    {
        $statusName = trim((string) ($raw['statusName'] ?? ''));
        if ($statusName === '') {
            return null;
        }

        return new TrackingEvent(
            occurredAt: self::parseTime((string) ($raw['acceptDt'] ?? '')),
            location: trim((string) ($raw['officeName'] ?? '')),
            description: $statusName,
            status: $this->mapStatus($statusName),
            raw: $raw,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $upper = strtoupper($description);
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($upper, strtoupper($keyword))) {
                return $status;
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持 'Y-m-d H:i:s'、'Y-m-d'、ISO8601（'Y-m-d\TH:i:s'、'Y-m-d\TH:i:sP'），解析失败为 null */
    private static function parseTime(mixed $date): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $date);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /** 接口可能按时间降序返回（最新在前），统一为升序，末条为最新 */
    /** @param TrackingEvent[] $events */
    private static function sortEvents(array $events): array
    {
        $count = count($events);
        if ($count < 2) {
            return $events;
        }
        $first = $events[0]->occurredAt;
        $last = $events[$count - 1]->occurredAt;

        return $first !== null && $last !== null && $first > $last
            ? array_reverse($events)
            : $events;
    }
}
