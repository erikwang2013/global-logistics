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
 * Royal Mail 国际件查询（Parcel Tracking API v2，OAuth2 client-credentials 认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（token 请求 Basic 头、
 * 事件顺序、eventCode/eventName 取值均为公开文档推断）。
 * 文档: https://developer.royalmail.com/parcel-tracking-v2
 */
final class RoyalMail implements CarrierInterface
{
    private const TOKEN_URL = 'https://api.royalmail.com/oauth/token';

    private const ENDPOINT = 'https://api.royalmail.com/mailpieces/v2/events';

    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            [
                'client_id' => (string) $this->config->get('royal-mail.client_id'),
                'client_secret' => (string) $this->config->get('royal-mail.client_secret'),
            ],
            basicAuth: true,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'mailPieceId' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ROYAL-MAIL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ROYAL-MAIL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[ROYAL-MAIL] 响应解析失败');
        }

        $mailPiece = $result['mailPieces'][0] ?? null;
        $events = [];
        if (is_array($mailPiece) && is_array($mailPiece['events'] ?? null)) {
            foreach ($mailPiece['events'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'royal-mail',
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
        throw new LogisticsException('royal-mail createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('royal-mail createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('royal-mail subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $locationRaw = $row['location'] ?? null;

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['timestamp'] ?? null),
            location: is_array($locationRaw) ? (string) ($locationRaw['locationName'] ?? '') : '',
            description: (string) ($row['eventName'] ?? ''),
            status: $this->mapStatus((string) ($row['eventCode'] ?? ''), (string) ($row['eventName'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $eventCode, string $eventName): TrackStatus
    {
        $text = strtolower($eventName);

        if ($eventCode === 'D' || str_contains($text, 'delivered')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'out for delivery')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'returned')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'held') || str_contains($text, 'unacceptable') || str_contains($text, 'failed')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'transit') || str_contains($text, 'received')) {
            return TrackStatus::IN_TRANSIT;
        }
        if (str_contains($text, 'despatched') || str_contains($text, 'collected')) {
            return TrackStatus::PENDING;
        }

        return TrackStatus::UNKNOWN;
    }

    /**
     * 多格式时间解析：ISO8601 带时区偏移（含 Z 与毫秒）、无偏移；全部失败返回 null。
     */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'] as $format) {
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
