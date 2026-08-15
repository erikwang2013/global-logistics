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
 * UPS 国际件查询（Track API v1，OAuth2 client-credentials 认证）。
 *
 * 文档: https://developer.ups.com/api/reference/tracking
 */
final class Ups implements CarrierInterface
{
    private const TOKEN_URL = 'https://onlinetools.ups.com/security/v1/oauth/token';

    private const ENDPOINT = 'https://onlinetools.ups.com/api/track/v1/details';

    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            [
                'client_id' => (string) $this->config->get('ups.client_id'),
                'client_secret' => (string) $this->config->get('ups.client_secret'),
            ],
            basicAuth: true,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '/' . urlencode($trackingNo), [
            'transId' => bin2hex(random_bytes(8)),
            'transactionSrc' => 'global-logistics',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[UPS %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[UPS %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[UPS] 响应解析失败');
        }

        $package = $result['trackResponse']['shipment'][0]['package'][0] ?? null;
        if (!is_array($package)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // UPS activity 按时间升序返回，末条为最新（复制模板时需核对）
        // 先取活动原始数组供 rawStatus 使用：循环后不能依赖 $activity 变量（循环可能未执行）
        $rawActivities = $package['activity'] ?? [];
        $events = [];
        foreach ($rawActivities as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $events[] = $this->mapEvent($activity);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ups',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($rawActivities[count($rawActivities) - 1]['status']['type'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('UPS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('UPS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('UPS subscribe 待实现');
    }

    /** @param array<string, mixed> $activity */
    private function mapEvent(array $activity): TrackingEvent
    {
        $locationRaw = $activity['location'] ?? null;
        $location = is_array($locationRaw)
            ? trim(($locationRaw['address']['city'] ?? '') . ' ' . ($locationRaw['address']['countryCode'] ?? ''))
            : '';

        return new TrackingEvent(
            occurredAt: $this->mapEventTime($activity['date'] ?? null, $activity['time'] ?? null),
            location: $location,
            description: (string) ($activity['status']['description'] ?? ''),
            status: $this->mapStatus((string) ($activity['status']['type'] ?? '')),
            raw: $activity,
        );
    }

    /** UPS activity 的 date/time 为紧凑格式，如 date="20260814" time="100000"（无分隔符、无时区） */
    private function mapEventTime(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        $raw = $date . (is_string($time) && $time !== '' ? $time : '000000');
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', $raw);

        return $dt === false ? null : $dt;
    }

    private function mapStatus(string $type): TrackStatus
    {
        // UPS status type 官方枚举 M/I/O/D/X/R
        return match (strtoupper($type)) {
            'D' => TrackStatus::DELIVERED,
            'M' => TrackStatus::PENDING,
            'I' => TrackStatus::IN_TRANSIT,
            'O' => TrackStatus::OUT_FOR_DELIVERY,
            'X' => TrackStatus::EXCEPTION,
            'R' => TrackStatus::RETURNED,
            default => TrackStatus::UNKNOWN,
        };
    }
}
