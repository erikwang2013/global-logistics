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
 * 瑞士邮政（SWISS-POST）适配器。
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（"Track consignments" API
 * 为 REST/JSON，OAuth2 client-credentials + scope；parcels/events 响应结构、
 * scope 取值均为开发者门户公开模式推断，凭据需注册 My Post Business 技术用户）。
 * 文档: https://developer.post.ch/en
 */
final class SwissPost implements CarrierInterface
{
    private const TOKEN_URL = 'https://api.post.ch/OAuth/token';

    private const ENDPOINT = 'https://api.post.ch/track/v1/parcels/{parcelCode}';

    private const DEFAULT_SCOPE = 'dcapi_track_parcels';

    private ?string $accessToken = null;

    private ?int $expiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{parcelCode}', urlencode($trackingNo), self::ENDPOINT) . '?' . http_build_query([
            'language' => (string) $this->config->get('swiss-post.language', 'en'),
        ]), [
            'Authorization' => 'Bearer ' . $this->token(),
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);

        if ($response->getStatusCode() === 401) {
            // token 过期：清除缓存后用新 token 重试一次
            $this->accessToken = null;
            $this->expiresAt = null;
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token());
            $response = $this->http->sendRequest($request);
        }

        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SWISS-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SWISS-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SWISS-POST] 响应解析失败');
        }
        if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) {
            throw new LogisticsException('[SWISS-POST] ' . $this->firstErrorMessage($result['errors']));
        }

        $parcel = $this->parcelFor($result, $trackingNo);
        if ($parcel === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($parcel['events'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'swiss-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($parcel['delivery']['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('SWISS-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('SWISS-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('SWISS-POST subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $text = (string) ($row['text'] ?? '');
        $status = (string) ($row['status'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['timestamp'] ?? null),
            location: (string) ($row['place'] ?? ''),
            description: $text !== '' ? $text : $status,
            status: $this->mapStatus((string) ($row['code'] ?? ''), $status, $text),
            raw: $row,
        );
    }

    private function mapStatus(string $code, string $status, string $text): TrackStatus
    {
        $lower = strtolower($code . ' ' . $status . ' ' . $text);

        if (str_contains($lower, 'delivered')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($lower, 'out for delivery') || str_contains($lower, 'delivery attempt')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($lower, 'return')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($lower, 'exception') || str_contains($lower, 'failed') || str_contains($lower, 'held')) {
            return TrackStatus::EXCEPTION;
        }
        // 'posted'/'collected' 必须先于 'transit'：事件 status 字段普遍为 "in transit"
        if (str_contains($lower, 'posted') || str_contains($lower, 'collected') || str_contains($lower, 'registered')) {
            return TrackStatus::PENDING;
        }
        if (str_contains($lower, 'transit') || str_contains($lower, 'accepted') || str_contains($lower, 'arriv')) {
            return TrackStatus::IN_TRANSIT;
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

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private function parcelFor(array $result, string $trackingNo): ?array
    {
        $parcels = $result['parcels'] ?? $result;
        if (!is_array($parcels)) {
            return null;
        }
        $upper = strtoupper($trackingNo);
        foreach ($parcels as $parcel) {
            if (!is_array($parcel)) {
                continue;
            }
            if (isset($parcel['parcelId']) && strtoupper((string) $parcel['parcelId']) === $upper) {
                return $parcel;
            }
        }

        // 未按 parcelId 匹配时取首条（单件查询场景）
        foreach ($parcels as $parcel) {
            if (is_array($parcel)) {
                return $parcel;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private function firstErrorMessage(array $errors): string
    {
        $first = $errors[0] ?? [];
        if (is_array($first)) {
            $message = (string) ($first['detail'] ?? $first['message'] ?? '');
            if ($message !== '') {
                return $message;
            }
        }

        return '业务错误';
    }

    private function token(): string
    {
        if ($this->accessToken !== null && ($this->expiresAt === null || $this->expiresAt > time())) {
            return $this->accessToken;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => (string) $this->config->get('swiss-post.client_id'),
            'client_secret' => (string) $this->config->get('swiss-post.client_secret'),
            'scope' => (string) $this->config->get('swiss-post.scope', self::DEFAULT_SCOPE),
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SWISS-POST %s] OAuth token 获取失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SWISS-POST %s] OAuth token 获取失败', $status));
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token'])) {
            throw new AuthException('[SWISS-POST] OAuth token 响应解析失败');
        }

        $this->accessToken = $body['access_token'];
        $this->expiresAt = isset($body['expires_in']) && is_numeric($body['expires_in'])
            // 预留 60s 时钟偏移缓冲
            ? time() + (int) $body['expires_in'] - 60
            : null;

        return $this->accessToken;
    }
}
