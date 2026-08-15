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
 * Lietuvos paštas（立陶宛邮政）国际件查询（官网跟踪服务的公开 JSON 接口，
 * GET + JSON）。
 *
 * VERIFIED-REQUIRED: 官方公开 API 文档（Dokumentas_API PDF）描述的是寄件/清单
 * 类方法（SOAP/XML 风格），未覆盖按条码查询轨迹的 JSON REST 接口；本适配器基于
 * 官网（post.lt/en/parcel-tracking）前端逆向推断，需实网验证（路径、查询参数、
 * 字段名与事件时间格式未确认；找不到单号时响应结构与错误码未确认）。
 * 文档: https://post.lt/sites/default/files/2020-03/Dokumentas_API_V1.1.16_2021.03.10_EN.pdf
 */
final class LithuaniaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.post.lt/api/tracking/{trackingNo}';

    /**
     * 事件关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'pristatoma'（派送中）须在 'pristatyta' 等一般关键词之前。
     */
    private const STATUS_MAP = [
        'out for delivery|pristatoma|įteikiama' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|pristatyta|įteikta|įteiktas' => TrackStatus::DELIVERED,
        'returned|return|grąžinta|grąžinama' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|neįteikta|sugadinta|atsisakyta|negalima pristatyti|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available|atsiimti|saugoma|siuntomate|picked up|atsiimta' => TrackStatus::PENDING,
        'in transit|transit|tranzite|gabena|priimta|rūšiuojama|išsiųsta|kelyje|received|arrived|departed|sorted|dispatched|accepted|shipped|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('lithuania-post.endpoint', self::ENDPOINT);
        $key = (string) $this->config->get('lithuania-post.key');
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($key !== '') {
            $headers['X-Api-Key'] = $key;
        }
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), $endpoint), $headers);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[LITHUANIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[LITHUANIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[LITHUANIA-POST] 响应解析失败');
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
            carrierCode: 'lithuania-post',
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
        throw new LogisticsException('lithuania-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('lithuania-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('lithuania-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dateTime'] ?? null),
            location: (string) ($row['place'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            status: $this->mapStatus((string) ($row['description'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        // 多字节文本（立陶宛语等）需 mb_strtolower，否则 UTF-8 大写字符不转小写
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

    /** 支持 ISO8601、'Y-m-d H:i' 等，解析失败返回 null */
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
            'd-m-Y H:i:s', 'd-m-Y H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!Y-m-d', '!d-m-Y',
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
