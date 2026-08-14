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
 * FedEx 国际件查询（Track API v1，OAuth2 client-credentials 认证）。
 *
 * 文档: https://developer.fedex.com/api/en-us/catalog/track.html
 */
final class FedEx implements CarrierInterface
{
    private const TOKEN_URL = 'https://apis.fedex.com/oauth/token';

    private const ENDPOINT = 'https://apis.fedex.com/track/v1/trackingnumbers';

    /** 事件描述关键词 => 统一状态（顺序敏感：先长后短，避免 'DELIVERED' 被 'PICKUP' 等短词干扰） */
    private const STATUS_MAP = [
        'PICKUP' => TrackStatus::PENDING,
        'PICKED UP' => TrackStatus::PENDING,
        'IN TRANSIT' => TrackStatus::IN_TRANSIT,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'DELIVERED' => TrackStatus::DELIVERED,
    ];

    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            [
                'client_id' => (string) $this->config->get('fedex.client_id'),
                'client_secret' => (string) $this->config->get('fedex.client_secret'),
            ],
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT,
            ['Content-Type' => 'application/json'],
            json_encode([
                'trackingNumberInfo' => ['trackingNumber' => $trackingNo],
                'includeDetailedScans' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[FEDEX %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[FEDEX %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[FEDEX] 响应解析失败');
        }

        $track = $result['output']['completeTrackResults'][0]['trackResults'][0] ?? null;
        if (!is_array($track)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // FedEx scanEvents 按时间升序返回，末条为最新（复制模板时需核对）
        $events = [];
        foreach ($track['scanEvents'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        $state = $this->mapState((string) ($track['statusByTrack']['state'] ?? ''));
        if ($state === TrackStatus::UNKNOWN) {
            // FedEx 运单级 state 未命中统一映射时，用末事件描述兜底
            $state = $latest->status;
        }

        return new Tracking(
            carrierCode: 'fedex',
            trackingNo: $trackingNo,
            status: $state,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($track['statusByTrack']['state'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('FEDEX createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('FEDEX createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('FEDEX subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['scanDescription'] ?? $row['statusDescription'] ?? '');

        $location = $row['scanLocation'] ?? null;
        $city = is_array($location) ? (string) ($location['city'] ?? '') : '';

        return new TrackingEvent(
            occurredAt: $this->mapEventTime($row['date'] ?? null, $row['time'] ?? null),
            location: $city,
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapEventTime(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }

        // FedEx 将日期和时间拆成两个字段，尝试先拼完整时间，失败再回退纯日期
        $raw = $date . ($time !== null && $time !== '' ? ' ' . $time : '');
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        if ($occurredAt === false) {
            $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            if ($occurredAt === false) {
                return null;
            }
        }

        return $occurredAt;
    }

    private function mapStatus(string $description): TrackStatus
    {
        $upper = strtoupper($description);
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($upper, $keyword)) {
                return $status;
            }
        }

        return TrackStatus::UNKNOWN;
    }

    private function mapState(string $state): TrackStatus
    {
        return match (strtoupper($state)) {
            'DELIVERED' => TrackStatus::DELIVERED,
            'PICKUP' => TrackStatus::PENDING,
            'OUT_FOR_DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
            'IN_TRANSIT' => TrackStatus::IN_TRANSIT,
            'EXCEPTION', 'FAILURE' => TrackStatus::EXCEPTION,
            'RETURNED' => TrackStatus::RETURNED,
            default => TrackStatus::UNKNOWN,
        };
    }
}
