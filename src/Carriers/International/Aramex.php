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
 * Aramex（中东快递）国际件查询（Tracking API v1，凭据内嵌于 JSON body，无 OAuth）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证
 * 文档: https://www.aramex.com/us/en/developers
 */
final class Aramex implements CarrierInterface
{
    private const ENDPOINT = 'https://ws.aramex.net/trackingapi/api/v1/track';

    /**
     * eventDescription 关键词 => 统一状态。
     * 'Out for delivery' 不含 'Delivered'，顺序上仍把 EXCEPTION/RETURN 放前；
     * 未命中关键词的其余描述按契约归为 IN_TRANSIT。
     */
    private const STATUS_MAP = [
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'FAILED' => TrackStatus::EXCEPTION,
        'ON HOLD' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'DELIVERED' => TrackStatus::DELIVERED,
        'SHIPMENT COLLECTED' => TrackStatus::PENDING,
        'RECEIVED' => TrackStatus::PENDING,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        // 认证凭据按契约内嵌于请求体顶层（userName/password/accountNumber + trackingNumbers）
        $body = json_encode([
            'userName' => (string) $this->config->get('aramex.user_name'),
            'password' => (string) $this->config->get('aramex.password'),
            'accountNumber' => (string) $this->config->get('aramex.account_number'),
            'trackingNumbers' => [
                ['trackingNumber' => $trackingNo],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ARAMEX %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ARAMEX %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[ARAMEX] 响应解析失败');
        }

        $results = $result['results'] ?? [];
        $track = is_array($results) ? ($results[0] ?? null) : null;
        $rawEvents = is_array($track) ? ($track['events'] ?? []) : [];
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
            carrierCode: 'aramex',
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
        throw new LogisticsException('aramex createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('aramex createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('aramex subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');

        return new TrackingEvent(
            occurredAt: self::parseTime($row['eventDate'] ?? null, $row['eventTime'] ?? null),
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

    /** 支持 'Y-m-d' + 'H:i:s' 分列、合并的 'Y-m-d H:i:s'、'Y-m-d' 及 ISO8601，解析失败为 null */
    private static function parseTime(mixed $date, mixed $time = null): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        $raw = is_string($time) && $time !== '' ? $date . ' ' . $time : $date;
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
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
