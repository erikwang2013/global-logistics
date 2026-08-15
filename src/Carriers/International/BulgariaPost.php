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
 * Bulgarian Posts（保加利亚邮政）国际件查询（官方电商集成 API，
 * POST + JSON，portalSessionGUID 会话鉴权）。
 *
 * VERIFIED-REQUIRED: 官方 API 基址 api.bgpost.bg（官网 e-shop 集成页公开），需实网
 * 验证（portalSessionGUID 需先用 portalUserGUID 创建会话；tracking 方法名、请求
 * 体字段与事件结构（日期/地点/事件描述）未确认；JSON-RPC 风格的可能性未排除；
 * 无会话 GUID 时留空即可）。
 * 文档: https://www.bgpost.bg/en/specifikacziya-za-obmen-na-danni
 */
final class BulgariaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.bgpost.bg/api/v1/tracking';

    /**
     * 事件关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'за доставка'（派送中）须在 'доставено' 等一般关键词之前。
     */
    private const STATUS_MAP = [
        'out for delivery|за доставка|разнасяне' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|доставено|връчено' => TrackStatus::DELIVERED,
        'returned|return|връщане|върнато' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|неуспешна|повредено|отказано|задържано|недоставено|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available|готово за получаване|чака|в пощенска станция|picked up|collected' => TrackStatus::PENDING,
        'in transit|transit|в транзит|транзит|прието|сортира|изпратено|на път|received|arrived|departed|sorted|dispatched|accepted|shipped|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('bulgaria-post.endpoint', self::ENDPOINT);
        $session = (string) $this->config->get('bulgaria-post.session_guid', (string) $this->config->get('bulgaria-post.key', ''));
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($session !== '') {
            $headers['portalSessionGUID'] = $session;
        }
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, $headers, json_encode([
            'barcode' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[BULGARIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[BULGARIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[BULGARIA-POST] 响应解析失败');
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || ($data['events'] ?? null) === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($data['events'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件若按时间降序返回则反转；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'bulgaria-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($data['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('bulgaria-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('bulgaria-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('bulgaria-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: (string) ($row['place'] ?? ''),
            description: (string) ($row['event'] ?? ''),
            status: $this->mapStatus((string) ($row['event'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        // 多字节文本（保加利亚语等）需 mb_strtolower，否则 UTF-8 大写字符不转小写
        $text = function_exists('mb_strtolower') ? mb_strtolower($description, 'UTF-8') : strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持 ISO8601、'd-m-Y H:i' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒（如 2023-12-18T11:20:06.123456+01:00）截断为 3 位毫秒
        if (preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!Y-m-d', '!d-m-Y', '!d.m.Y',
        ] as $format) {
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
