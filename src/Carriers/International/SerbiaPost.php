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
 * Pošta Srbije（塞尔维亚邮政）国际件查询（官网 "Praćenje pošiljke" 跟踪服务的
 * 公开 JSON 接口，GET + JSON）。
 *
 * VERIFIED-REQUIRED: 官方未发布公开 API 文档，契约基于官网跟踪页（posta.rs/tracking）
 * 前端逆向推断，需实网验证（路径、请求参数、字段名与事件时间格式
 * "DD-MM-YYYY HH:MM" 未确认；找不到单号时响应结构与错误码未确认；如无
 * X-Api-Key 则留空即可）。
 * 文档: https://www.posta.rs/tracking/
 */
final class SerbiaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.posta.rs/api/tracking/{trackingNo}';

    /**
     * eventDescription 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'na dostavi'（派送中）须在 'dostavljena' 等一般关键词之前。
     */
    private const STATUS_MAP = [
        'out for delivery|na dostavi|na adresi' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|uručena|isporučena|dostavljena|predata' => TrackStatus::DELIVERED,
        'returned|return|vraćena|povrat' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|oštećena|odbijena|neisporučena|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available|na pošti|čekanje|picked up|preuzeta' => TrackStatus::PENDING,
        'in transit|transit|u tranzitu|prihvaćena|primljena|sortirana|poslata|na putu|received|arrived|departed|sorted|dispatched|accepted|shipped|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('serbia-post.endpoint', self::ENDPOINT);
        $key = (string) $this->config->get('serbia-post.key');
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
            throw new AuthException(sprintf('[SERBIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SERBIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SERBIA-POST] 响应解析失败');
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
        // 官网事件按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'serbia-post',
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
        throw new LogisticsException('serbia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('serbia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('serbia-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? null),
            location: (string) ($row['eventPlace'] ?? ''),
            description: (string) ($row['eventDescription'] ?? ''),
            status: $this->mapStatus((string) ($row['eventDescription'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        // 多字节文本（塞尔维亚语等）需 mb_strtolower，否则 UTF-8 大写字符不转小写
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

    /** 支持 'd-m-Y H:i'、ISO8601 等，解析失败返回 null */
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
