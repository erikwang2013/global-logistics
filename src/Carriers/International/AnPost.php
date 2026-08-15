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
 * An Post（爱尔兰邮政）国际件查询（官方公共 Tracking API，POST +
 * Ocp-Apim-Subscription-Key，GetEvents 方法）。
 *
 * VERIFIED-REQUIRED: 契约基于 An Post 官网移动端/网页同源 API（itsvic-dev/deliveries
 * 开源项目逆向），需实网验证（Subscription-Key 为官网公开密钥，建议从配置读取；
 * GetEventsResult 按时间降序返回（首条最新）；date 为 ISO8601；traceCode 14 表示
 * 已交付、4 表示派送中、13 表示派送失败；单号无效时 GetEventsResult 为空数组）。
 * 文档: https://www.anpost.com/Post-Parcels/Track/Search
 */
final class AnPost implements CarrierInterface
{
    private const ENDPOINT = 'https://apim-anpost-apwebapis.anpost.com/ttservice-public-apweb/GetEvents';

    /**
     * activity/reason 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|prepared for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered' => TrackStatus::DELIVERED,
        'returned|return' => TrackStatus::RETURNED,
        'tried to deliver|missed|undeliver|failed|refused|held|damaged|not collected' => TrackStatus::EXCEPTION,
        'on its way to us|preadvice|handed to|we have your item|processed|in warehouse|ready for collection|collected' => TrackStatus::PENDING,
        'sorted in|sorted|in transit|transit|received|arrived|departed|dispatched|on its way|being prepared' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('an-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Ocp-Apim-Subscription-Key' => (string) $this->config->get('an-post.subscription_key', ''),
        ], json_encode([
            'getEvents' => [
                'barcodeItem' => $trackingNo,
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[AN-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[AN-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !is_array($result['getEventsResponse']['GetEventsResult'] ?? null)) {
            throw new LogisticsException('[AN-POST] 响应解析失败');
        }

        $rawEvents = $result['getEventsResponse']['GetEventsResult'];
        if ($rawEvents === []) {
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
        // 官方按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'an-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['traceCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('an-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('an-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('an-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $activity = (string) ($row['activity'] ?? '');
        $reason = (string) ($row['reason'] ?? '');
        $description = $activity !== '' ? $activity : $reason;

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $this->mapStatus($activity . ' ' . $reason),
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
