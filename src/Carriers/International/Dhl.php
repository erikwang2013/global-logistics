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
 * DHL Express 国际件查询（MyDHL API，OAuth2 client-credentials 认证）。
 *
 * 文档: https://developer.dhl.com/api-reference/shipment-tracking
 */
final class Dhl implements CarrierInterface
{
    private const TOKEN_URL = 'https://api.dhl.com/mydhlapi/auth';

    private const ENDPOINT = 'https://api.dhl.com/mydhlapi/shipments';

    /** DHL statusCode => 统一状态（事件级 statusCode 为空或未命中时回退到运单级 statusCode） */
    private const STATUS_MAP = [
        'delivered' => TrackStatus::DELIVERED,
        'pre-transit' => TrackStatus::PENDING,
        'transit' => TrackStatus::IN_TRANSIT,
        'failure' => TrackStatus::EXCEPTION,
        'exception' => TrackStatus::EXCEPTION,
        'returned' => TrackStatus::RETURNED,
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
                'client_id' => (string) $this->config->get('dhl.client_id'),
                'client_secret' => (string) $this->config->get('dhl.client_secret'),
            ],
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[DHL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[DHL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DHL] 响应解析失败');
        }

        $shipments = $result['shipments'] ?? [];
        $shipment = is_array($shipments) ? ($shipments[0] ?? null) : null;
        if (!is_array($shipment)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 事件按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $shipmentStatus = (string) ($shipment['status']['statusCode'] ?? '');
        $events = [];
        foreach ($shipment['events'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row, $shipmentStatus);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'dhl',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $shipmentStatus,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('DHL createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('DHL createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('DHL subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row, string $shipmentStatus): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) ($row['timestamp'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', (string) ($row['timestamp'] ?? ''));
            if ($occurredAt === false) {
                $occurredAt = null;
            }
        }

        $address = $row['location']['address'] ?? null;
        if (is_array($address)) {
            $parts = array_filter([
                (string) ($address['city'] ?? ''),
                (string) ($address['countryCode'] ?? ''),
            ], fn (string $v): bool => $v !== '');
            $location = implode(', ', $parts);
        } else {
            $location = '';
        }

        $statusCode = (string) ($row['statusCode'] ?? '');
        if (!isset(self::STATUS_MAP[$statusCode])) {
            $statusCode = $shipmentStatus;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: $location,
            description: (string) ($row['description'] ?? ''),
            status: self::STATUS_MAP[$statusCode] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }
}
