<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

/**
 * Yodel（英国 Yodel）国际件查询（Tracking API，OAuth2 client-credentials 认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（token 端点、追踪资源路径与
 * 事件字段名按 Yodel 开发者门户 Tracking API 推断；沙箱 api-sb.yodel.co.uk 的
 * tracking/v1.0 路由存在但未公开资源形态）。
 * 文档: https://developer.yodel.co.uk
 */
final class Yodel implements CarrierInterface
{
    private const TOKEN_URL = 'https://api.yodel.co.uk/oauth/token';

    private const BASE_URL = 'https://api.yodel.co.uk';

    private const TRACK_PATH = '/tracking/v1.0/parcels/';

    /**
     * eventDescription 关键词 => 统一状态；未命中关键词的其余描述按契约归为 IN_TRANSIT。
     */
    private const STATUS_MAP = [
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'FAILED' => TrackStatus::EXCEPTION,
        'HELD' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'DELIVERED' => TrackStatus::DELIVERED,
        'COLLECTED' => TrackStatus::PENDING,
        'RECEIVED' => TrackStatus::PENDING,
    ];

    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            (string) $this->config->get('yodel.token_url', self::TOKEN_URL),
            [
                'client_id' => (string) $this->config->get('yodel.client_id'),
                'client_secret' => (string) $this->config->get('yodel.client_secret'),
            ],
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $base = (string) $this->config->get('yodel.base_url', self::BASE_URL);

        $request = new \GuzzleHttp\Psr7\Request('GET', $base . self::TRACK_PATH . rawurlencode($trackingNo), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[YODEL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[YODEL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[YODEL] 响应解析失败');
        }

        $parcels = $result['parcels'] ?? [];
        $parcel = is_array($parcels) ? ($parcels[0] ?? null) : null;
        $rawEvents = is_array($parcel) ? ($parcel['trackingEvents'] ?? []) : [];
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
        // 事件按时间升序，末条为最新；若返回降序则反转
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yodel',
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
        throw new LogisticsException('yodel createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('yodel createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('yodel subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDateTime'] ?? null),
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

    /** 支持 ISO8601 带时区（含毫秒）、'Y-m-d H:i:s'、'Y-m-d H:i'、'Y-m-d'，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
