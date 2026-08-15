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
 * 极兔国际（J&T Express International）国际件查询（J&T 开放平台 Track API，POST JSON + MD5 签名）。
 *
 * VERIFIED-REQUIRED: 契约基于国内极兔 openapi 的 sign/timestamp/ApiKey 签名模式与
 * J&T 开放平台（open.jtexpress.com.cn，apiAccount + privateKey）文档推断，需实网验证
 * （国际端点 openapi.jtexpress.com.cn、响应 data[].traces 事件字段
 * track_time/station_name/track_desc/status 为文档推断；单号与国内极兔同为 JT 前缀，
 * 默认仅显式调用）。
 * 文档: https://open.jtexpress.com.cn/
 */
final class JtIntl implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.jtexpress.com.cn/API/External_GetTraces.json';

    /**
     * 状态文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，中文 + 英文）。
     * '派送中' 与 '已签收' 无前缀重叠，但统一将更具体状态放在前面。
     */
    private const STATUS_MAP = [
        '已揽收|picked up|picked_up' => TrackStatus::PENDING,
        '运输中|in transit|in_transit|transit' => TrackStatus::IN_TRANSIT,
        '派送中|out for delivery|out_for_delivery' => TrackStatus::OUT_FOR_DELIVERY,
        '已签收|delivered|signed' => TrackStatus::DELIVERED,
        '异常|exception|failed' => TrackStatus::EXCEPTION,
        '退回|returned|return' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('jt-intl.endpoint', self::ENDPOINT);
        $apiKey = (string) $this->config->get('jt-intl.api_key');
        $secret = (string) $this->config->get('jt-intl.secret', '');

        $timestamp = (string) time();
        $sign = md5($secret . $timestamp);

        $body = json_encode([
            'sign' => $sign,
            'timestamp' => $timestamp,
            'msg_type' => 'GET_TRACES',
            'data' => [
                'tracking_number' => $trackingNo,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'ApiKey' => $apiKey,
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[JT-INTL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[JT-INTL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[JT-INTL] 响应解析失败');
        }

        if (($result['success'] ?? false) !== true) {
            throw new LogisticsException(sprintf('[JT-INTL %s] %s', (string) ($result['code'] ?? ''), (string) ($result['message'] ?? '')));
        }

        $item = $result['data'][0] ?? [];
        $rawEvents = is_array($item) ? ($item['traces'] ?? []) : [];
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
            carrierCode: 'jt-intl',
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
        throw new LogisticsException('jt-intl createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('jt-intl createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('jt-intl subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['track_time'] ?? null),
            location: (string) ($row['station_name'] ?? ''),
            description: (string) ($row['track_desc'] ?? ''),
            status: $this->mapStatus((string) ($row['status'] ?? '') . ' ' . (string) ($row['track_desc'] ?? '')),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒
        if (preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
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
