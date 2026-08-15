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
 * DPD（欧洲）国际件查询（DPD 多国 Tracking API，HTTP Basic 认证，无 OAuth）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证
 * 文档: https://api.dpd.it/shipment/track
 */
final class Dpd implements CarrierInterface
{
    private const ENDPOINT = 'https://api.dpd.it/shipment/track';

    /**
     * eventDescription 关键词 => 统一状态。
     * 'Out for delivery' 不含 'Delivered'，顺序上仍把 EXCEPTION/RETURN 放前；
     * 未命中关键词的其余描述按契约归为 IN_TRANSIT。
     */
    private const STATUS_MAP = [
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'DELIVERED' => TrackStatus::DELIVERED,
        'PARCEL PICKED UP' => TrackStatus::PENDING,
        'COLLECTION' => TrackStatus::PENDING,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $user = (string) $this->config->get('dpd.user_name');
        $password = (string) $this->config->get('dpd.password');
        // DPD 多国 API 为 HTTP Basic 认证（非 OAuth token 端点），直接携带 Authorization 头；
        // Ups 的 OAuthTokenClient basicAuth:true 仅适用于 token 端点，不适用本场景
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '/' . urlencode($trackingNo), [
            'Authorization' => 'Basic ' . base64_encode($user . ':' . $password),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[DPD %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[DPD %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DPD] 响应解析失败');
        }

        $data = $result['trackingData'] ?? null;
        $rawEvents = is_array($data) ? ($data['events'] ?? []) : [];
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
        // 事件按时间升序，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = self::sortEvents($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'dpd',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['eventDescription'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('dpd createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('dpd createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('dpd subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');

        return new TrackingEvent(
            occurredAt: self::parseTime($row['eventDate'] ?? null),
            location: (string) ($row['eventLocation'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $upper = strtoupper($description);
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($upper, $keyword)) {
                return $status;
            }
        }

        return TrackStatus::IN_TRANSIT;
    }

    /** 支持合并的 'Y-m-d H:i:s'、'Y-m-d'、ISO8601（'Y-m-d\TH:i:s'、'Y-m-d\TH:i:sP'），解析失败为 null */
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
