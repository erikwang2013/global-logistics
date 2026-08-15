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
 * 泰国邮政（Thailand Post）国际件查询（官方 Track & Trace REST API，
 * AppToken 换临时 access token，再以 Token 头查询 barcode）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（AppToken 需在
 * track.thailandpost.co.th 注册；track 响应字段名为开发者指南公开模式推断）。
 * 文档: https://track.thailandpost.co.th/developerGuide
 */
final class ThailandPost implements CarrierInterface
{
    private const TOKEN_URL = 'https://trackapi.thailandpost.co.th/post/api/v1/authenticate/token';

    private const ENDPOINT = 'https://trackapi.thailandpost.co.th/post/api/v1/track';

    /**
     * status/status_description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 泰文关键词按原文匹配；'ส่งถึง'（已送达）必须先于其他状态。
     */
    private const STATUS_MAP = [
        'delivered|ส่งถึง' => TrackStatus::DELIVERED,
        'out for delivery|นำจ่าย' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|return|ส่งคืน' => TrackStatus::RETURNED,
        'failed|exception|problem|ปัญหา' => TrackStatus::EXCEPTION,
        'picked up|collected|ready for pickup|รับฝาก' => TrackStatus::PENDING,
        'in transit|transit|received|arrived|departed|sorted|อยู่ระหว่างการขนส่ง' => TrackStatus::IN_TRANSIT,
    ];

    private ?string $accessToken = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('thailand-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Token ' . $this->token(),
        ], json_encode([
            'status' => 'all',
            'language' => (string) $this->config->get('thailand-post.language', 'EN'),
            'barcode' => [$trackingNo],
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[THAILAND-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[THAILAND-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[THAILAND-POST] 响应解析失败');
        }
        if (($result['status'] ?? null) !== true) {
            throw new LogisticsException('[THAILAND-POST] ' . (string) ($result['message'] ?? '业务错误'));
        }

        $rawEvents = $result['track'][$trackingNo] ?? $result['items'] ?? [];
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
            carrierCode: 'thailand-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('thailand-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('thailand-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('thailand-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $status = (string) ($row['status'] ?? '');
        $description = (string) ($row['status_description'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['status_date'] ?? null),
            location: trim((string) ($row['location'] ?? '')),
            description: $description !== '' ? $description : $status,
            status: $this->mapStatus($status . ' ' . $description),
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

    /** 支持 'Y-m-d H:i:s'、'Y-m-d H:i'、ISO8601 等，解析失败返回 null */
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

    /** 用 AppToken 换临时 access token（带内存缓存） */
    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode([
            'token' => (string) $this->config->get('thailand-post.app_token'),
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[THAILAND-POST %s] OAuth token 获取失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[THAILAND-POST %s] OAuth token 获取失败', $status));
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || ($body['status'] ?? null) !== true || !is_string($body['response']['token'] ?? null)) {
            throw new AuthException('[THAILAND-POST] OAuth token 响应解析失败');
        }

        $this->accessToken = $body['response']['token'];

        return $this->accessToken;
    }
}
