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
 * RomaniaPost（罗马尼亚邮政）国际件查询（官网 Track & Trace 表单查询，POST + cod，
 * 无认证；响应 HTML 表格按 Date | Hour | Item status 解析）。
 *
 * VERIFIED-REQUIRED: 无官方公开 API，契约基于官网 track-trace.html 逆向（页面文案明确
 * 摘要/详细报表含 Date(DD.MM.YYYY) | Hour(HH:MM) | Item status 三列，提交参数名推断为
 * cod，需实网验证；日期+小时拼合为事件时间；无效单号时无数据行或页面无表格，按无事件
 * 处理；状态关键词为 Poșta Română 官网标准罗马尼亚语描述）。
 * 文档: https://www.posta-romana.ro/track-trace.html
 */
final class RomaniaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.posta-romana.ro/track-trace.html';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，罗马尼亚语）。
     * 'in livrare'（派送中）须先于 'livrat'（已交付）等一般关键词。
     */
    private const STATUS_MAP = [
        'out for delivery|in livrare|livrare la adresa|predare la adresa' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|livrat|predat destinatarului|fost livrat' => TrackStatus::DELIVERED,
        'returned|return|retur|restituit|revenit' => TrackStatus::RETURNED,
        'failed|exception|nerevendicat|refuzat|avariat|nelivrat|imposibil de livrat' => TrackStatus::EXCEPTION,
        'ready for pickup|in depozit|la depozit|retinut|pastrat|depozitat|pending' => TrackStatus::PENDING,
        'in transit|in_transit|traseu|transit|expedi|plecat|sosit|prelucr|acceptat|inregistrat|vam|import|export|colet|trimitere' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('romania-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'text/html,application/xhtml+xml',
        ], http_build_query([
            'cod' => $trackingNo,
            'terms' => 'on',
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ROMANIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ROMANIA-POST %s] 接口错误', $status));
        }

        $body = (string) $response->getBody();
        if (stripos($body, '<table') === false) {
            throw new LogisticsException('[ROMANIA-POST] 响应解析失败');
        }

        $events = [];
        foreach ($this->extractRows($body) as $row) {
            if ($row === []) {
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
            carrierCode: 'romania-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['description'] ?? '',
            raw: ['html' => $body],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('romania-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('romania-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('romania-post subscribe 待实现');
    }

    /**
     * 从响应 HTML 中提取跟踪表格行（Date | Hour | Item status 或 Date | Item status），
     * 跳过表头行。
     *
     * @return array<int, array{date: string, hour: string, description: string}>
     */
    private function extractRows(string $html): array
    {
        $rows = [];
        $patterns = [
            '~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~si',
            '~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~si',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $date = trim(strip_tags($m[1]));
                    if (strtolower($date) === 'date') {
                        continue; // 表头行
                    }
                    if (isset($m[3])) {
                        $rows[] = [
                            'date' => $date,
                            'hour' => trim(strip_tags($m[2])),
                            'description' => trim(strip_tags($m[3])),
                        ];
                    } else {
                        $rows[] = [
                            'date' => $date,
                            'hour' => '',
                            'description' => trim(strip_tags($m[2])),
                        ];
                    }
                }
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array{date: string, hour: string, description: string} $row
     */
    private function mapEvent(array $row): TrackingEvent
    {
        $dateTime = $row['date'];
        if ($row['hour'] !== '') {
            $dateTime .= ' ' . $row['hour'];
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($dateTime),
            location: '',
            description: $row['description'],
            status: $this->mapStatus($row['description']),
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

    /** 支持 'd.m.Y H:i'（官网日期+小时格式）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒（至少 6 位数字，避免误伤 d.m.Y 点分日期）
        if (preg_match('/^(.+\.)(\d{6,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'd.m.Y H:i:s', 'd.m.Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d.m.Y', '!d/m/Y', '!d-m-Y', '!Y-m-d',
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
