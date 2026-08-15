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
 * Pakistan Post（巴基斯坦邮政）国际件查询（官方 EMTTS 跟踪系统 ep.gov.pk 表单查询，
 * POST + TrackingId，无认证；响应 HTML 表格按 Date | Location | Status 解析）。
 *
 * VERIFIED-REQUIRED: 无官方公开 API，契约基于官方 EMTTS 跟踪页逆向（ep.gov.pk/track.asp
 * 为经典 ASP 表单页，提交 TrackingId 后返回含结果表格的 HTML，列与顺序按官网实际输出，
 * 本适配器按 3 列 Date | Location | Status 与 2 列 Date | Status 容错解析；日期无时间部分，
 * 本适配器按当日零点处理；无效单号时无数据行或页面无表格，按无事件处理；状态关键词为
 * EMTTS 标准英文描述）。
 * 文档: https://ep.gov.pk/track.asp
 */
final class PakistanPost implements CarrierInterface
{
    private const ENDPOINT = 'https://ep.gov.pk/track.asp';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|on delivery run|delivery run' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|delivery complete|signed' => TrackStatus::DELIVERED,
        'returned|return to sender' => TrackStatus::RETURNED,
        'failed|exception|undeliver|unclaimed|refused|held|missing|damaged' => TrackStatus::EXCEPTION,
        'booked|accepted|pending|ready for pickup|available for pickup' => TrackStatus::PENDING,
        'in transit|in_transit|transit|arrived|departed|dispatched|despatched|received|processed|sorted|forwarded|en route|on the way' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('pakistan-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'text/html,application/xhtml+xml',
        ], http_build_query([
            'TrackingId' => $trackingNo,
            'Submit' => 'Track',
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[PAKISTAN-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[PAKISTAN-POST %s] 接口错误', $status));
        }

        $body = (string) $response->getBody();
        if (stripos($body, '<table') === false) {
            throw new LogisticsException('[PAKISTAN-POST] 响应解析失败');
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
            carrierCode: 'pakistan-post',
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
        throw new LogisticsException('pakistan-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('pakistan-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('pakistan-post subscribe 待实现');
    }

    /**
     * 从响应 HTML 中提取跟踪表格行（Date | Location | Status 或 Date | Status），
     * 跳过表头行。
     *
     * @return array<int, array{date: string, location: string, description: string}>
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
                            'location' => trim(strip_tags($m[2])),
                            'description' => trim(strip_tags($m[3])),
                        ];
                    } else {
                        $rows[] = [
                            'date' => $date,
                            'location' => '',
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
     * @param array{date: string, location: string, description: string} $row
     */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date']),
            location: $row['location'],
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

    /** 支持 'd/m/Y'（EMTTS 官网格式，无时间部分）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d/m/Y', '!d-m-Y', '!Y-m-d',
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
