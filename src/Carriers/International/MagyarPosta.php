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
 * Magyar Posta（匈牙利邮政）国际件查询（MPLAPI 官方 Track & Trace API，POST JSON，Bearer 认证）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 MPLAPI 技术文档推断，需实网验证（legacy 同步端点
 * /v2/nvomkovetes/registered 的请求参数 language/lds/state 与响应 RegisteredTrackAndTraceResult
 * 事件字段 event_timestamp/event_type/post_name 为文档推断；官方当前主推 OAuth2 + 异步
 * PULL 端点 /v2/mplapi-tracking/tracking（trackingGUID 轮询），本适配器仅覆盖同步 legacy
 * 查询形态，Bearer access_token 需在 devportal 申请签发）。
 * 文档: https://devportal.posta.hu/en/en/home
 */
final class MagyarPosta implements CarrierInterface
{
    private const ENDPOINT = 'https://core.api.posta.hu/v2/nvomkovetes/registered';

    /**
     * 事件文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含匈牙利语）。
     * 'kézbesítés alatt'（派送中）必须先于 'kézbesítve'（已投递）相关词。
     */
    private const STATUS_MAP = [
        'kézbesítés alatt|kézbesítésre|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'kézbesítve|delivered|sikeresen kézbesítve|átadva' => TrackStatus::DELIVERED,
        'visszaküld|returned|return' => TrackStatus::RETURNED,
        'sikertelen kézbesítés|failed|exception|meghiúsult' => TrackStatus::EXCEPTION,
        'átvehető|átvettük|received|feladótól átvettük|ready for pick up' => TrackStatus::PENDING,
        'szállítás alatt|transit|futóban|szortíroz|in transit' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('magyar-posta.endpoint', self::ENDPOINT);
        $token = (string) $this->config->get('magyar-posta.access_token');

        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, $headers, (string) json_encode([
            'language' => 'hu',
            'lds' => $trackingNo,
            'state' => 'all',
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[MAGYAR-POSTA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[MAGYAR-POSTA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[MAGYAR-POSTA] 响应解析失败');
        }

        $item = $result['result']['item'] ?? null;
        $rawEvents = is_array($item) ? ($item['events'] ?? []) : [];
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
            carrierCode: 'magyar-posta',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) (is_array($item) ? ($item['last_event_type'] ?? '') : ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('magyar-posta createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('magyar-posta createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('magyar-posta subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['event_type'] ?? '');
        if ($description === '') {
            $description = (string) ($row['description'] ?? '');
        }
        $location = (string) ($row['post_name'] ?? '');
        if ($location === '') {
            $location = (string) ($row['location'] ?? '');
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['event_timestamp'] ?? ($row['timestamp'] ?? null)),
            location: $location,
            description: $description,
            status: $this->mapStatus($description),
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
