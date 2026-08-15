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
 * Pos Malaysia（马来西亚邮政）国际件查询（官方网关 JSON 接口
 * v2TrackNTraceWebApiJson，GET + X-User-Key）。
 *
 * VERIFIED-REQUIRED: 契约基于官方网页接口逆向（ping/posmalaysia_tracktrace.py
 * 开源脚本），需实网验证（X-User-Key 为 pos.com.my 跟踪页内嵌的公开密钥，需从
 * 页面动态获取或配置；响应为事件数组，字段 date/process/office，按时间降序返回
 * （最新在前）；首步含 ErrorDetails 表示业务错误；date 格式如 "08 Sep 2020,
 * 02:33:50 PM"）。
 * 文档: https://pos.com.my/track-trace
 */
final class PosMalaysia implements CarrierInterface
{
    private const ENDPOINT = 'https://apis.pos.com.my/apigateway/as2corporate/api/v2trackntracewebapijson/v1/';

    /**
     * process 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out for delivery attempted' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered' => TrackStatus::DELIVERED,
        'returned|return|refused' => TrackStatus::RETURNED,
        'failed|exception|held|unclaimed|undelivered|damaged|missed' => TrackStatus::EXCEPTION,
        'picked up|collected|ready for collection|awaiting|accepted|booked' => TrackStatus::PENDING,
        'dispatch|in transit|transit|processed|received|arrived|sorted|on the way|sent' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('pos-malaysia.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'id' => $trackingNo,
            'Culture' => (string) $this->config->get('pos-malaysia.culture', 'en'),
        ]), [
            'Accept' => 'application/json',
            'X-User-Key' => (string) $this->config->get('pos-malaysia.user_key', ''),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POS-MALAYSIA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POS-MALAYSIA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !array_is_list($result)) {
            throw new LogisticsException('[POS-MALAYSIA] 响应解析失败');
        }
        if ($result === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 业务错误：首步带 ErrorDetails（如单号格式无效/未找到）
        $first = $result[0] ?? null;
        if (is_array($first) && !empty($first['ErrorDetails'])) {
            throw new LogisticsException('[POS-MALAYSIA] ' . (string) ($first['ErrorDetails'] ?? '业务错误'));
        }

        $events = [];
        foreach ($result as $row) {
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
            carrierCode: 'pos-malaysia',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['process'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('pos-malaysia createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('pos-malaysia createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('pos-malaysia subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        // 新旧接口字段名兼容（date_time/date、event/office）
        $date = (string) ($row['date_time'] ?? $row['date'] ?? '');
        $process = (string) ($row['process'] ?? '');
        $location = (string) ($row['event'] ?? $row['office'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($date),
            location: $location,
            description: $process,
            status: $this->mapStatus($process),
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

    /** 支持 'd-M-Y, g:i:s A'（官网格式）、'd/m/Y g:i:s A'、ISO8601 等，解析失败返回 null */
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
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'j-M-Y, g:i:s A', 'j-M-Y g:i:s A', 'd-M-Y, g:i:s A', 'd-M-Y g:i:s A', 'd-M-Y H:i:s', 'd-M-Y H:i',
            'j M Y, g:i:s A', 'j M Y, H:i:s', 'd M Y, g:i:s A', 'd M Y, H:i:s', 'd M Y g:i:s A', 'd M Y H:i:s',
            'd/m/Y g:i:s A', 'd-m-Y g:i:s A', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!Y-m-d', '!d-m-Y', '!d/m/Y',
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
