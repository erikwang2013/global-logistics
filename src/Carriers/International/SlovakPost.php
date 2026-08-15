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
 * Slovak Post（Slovenská pošta，斯洛伐克邮政）国际件查询（官方 T&T REST API，
 * GET 公共端点，支持 S10 国家码 SK 尾码，如 RA123456785SK）。
 *
 * VERIFIED-REQUIRED: 契约按官方《Manuál pre implementáciu T&T API》（2025-07-01 生效）
 * 实现，需实网验证（q 参数接受单号，l 参数可选 sk/en 默认 sk；响应 status 为 ok、
 * results[].events 按时间升序返回；events 可能为空数组或缺失；单号格式无效时
 * result.status=invalid_format 且无 events；官方未要求鉴权头）。事件 stateCode 官方枚举：
 * received/transit/notified/delivered/returning/returned。
 * 文档: https://www.posta.sk/subory/39030/manual-pre-implementaciu-tt-api.pdf
 * 查询页: https://tandt.posta.sk
 */
final class SlovakPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.posta.sk/tracking';

    /**
     * stateCode/eventDescription 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 官方 stateCode 枚举优先：delivered/notified/returned 等；英文/斯洛伐克语文案兜底。
     */
    private const STATUS_MAP = [
        'out for delivery|v doručovaní|na doručenie' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|vydaná|doručen' => TrackStatus::DELIVERED,
        'returning|returned|return|vráten' => TrackStatus::RETURNED,
        'failed|exception|held|unclaimed|undeliver' => TrackStatus::EXCEPTION,
        'notified|ready for pickup|available for pickup|picked up|uložená' => TrackStatus::PENDING,
        'received|transit|podaná|prevzatá|v preprave|arrived|departed|sorted|dispatched|accepted|in transit|in_transport' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('slovak-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'q' => $trackingNo,
            'l' => (string) $this->config->get('slovak-post.locale', 'en'),
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SLOVAK-POST %d] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SLOVAK-POST %d] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SLOVAK-POST] 响应解析失败');
        }

        $item = $result['results'][0] ?? null;
        $rawEvents = is_array($item) ? ($item['events'] ?? []) : null;
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
        // 官方按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'slovak-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['stateCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('slovak-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('slovak-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('slovak-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['detailDescription'] ?? '');
        if ($description === '') {
            $description = (string) ($row['detailCode'] ?? '');
        }
        $location = '';
        if (is_array($row['postOffice'] ?? null)) {
            $location = implode(' ', array_filter([
                (string) ($row['postOffice']['name'] ?? ''),
                (string) ($row['postOffice']['city'] ?? ''),
            ]));
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['localDate'] ?? null),
            location: $location,
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['stateCode'] ?? '')),
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

    /** 支持 ISO8601（官方 localDate 为无时区 'Y-m-d\TH:i:s'）、'Y-m-d H:i:s' 等，解析失败返回 null */
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

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!Y-m-d', '!d/m/Y', '!d-m-Y',
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
