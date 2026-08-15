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
 * PHLPost（菲律宾邮政）国际件查询（官方跟踪门户 tracking.phlpost.gov.ph 的 JSON 接口，
 * GET + trackingNumber 参数，无认证；响应 items.TrackingList 为事件数组，按时间升序）。
 *
 * VERIFIED-REQUIRED: 契约基于官方跟踪门户逆向（tracking.phlpost.gov.ph 为 Angular
 * 单页应用，页面模板直接引用 items.Total/items.Transit/items.Delivered/items.NotFound/
 * items.TrackingNumber/items.Status/items.StatusDateStrings 字段，端点路径与参数名推断为
 * GetTrackingInfo?trackingNumber=，需实网验证；TrackingList 事件含 trackingNumber/status/
 * eventDate/description/location；无此单号时 TrackingList 为空数组或 NotFound 计数，按无
 * 事件处理；状态关键词为 PHLPost 标准英文描述）。
 * 文档: https://tracking.phlpost.gov.ph/
 */
final class PhlPost implements CarrierInterface
{
    private const ENDPOINT = 'https://tracking.phlpost.gov.ph/api/Tracking/GetTrackingInfo';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out for physical delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|received by|signed by' => TrackStatus::DELIVERED,
        'returned|return' => TrackStatus::RETURNED,
        'failed|exception|undeliver|unclaimed|held|missing|damaged|refused' => TrackStatus::EXCEPTION,
        'ready for pickup|available for pickup|pending|incoming|accepted|booked' => TrackStatus::PENDING,
        'in transit|in_transit|transit|arrived|departed|sorted|processed|dispatched|forwarded|inbound|outbound|received|customs|en route' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('phl-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[PHL-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[PHL-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[PHL-POST] 响应解析失败');
        }

        $items = $result['items'] ?? null;
        $list = null;
        if (is_array($items)) {
            $list = $items['TrackingList'] ?? $items['trackingList'] ?? $items['List'] ?? null;
        }
        if (!is_array($list)) {
            $list = $result['TrackingList'] ?? $result['trackingList'] ?? null;
        }
        if (!is_array($list) || $list === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($list as $row) {
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
            carrierCode: 'phl-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $this->pick($latest->raw, ['status', 'eventStatus']),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('phl-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('phl-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('phl-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = $this->pick($row, ['description', 'statusDescription', 'event', 'status']);

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? $row['date'] ?? $row['statusDate'] ?? null),
            location: $this->pick($row, ['location', 'office', 'city', 'details']),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s'、'd/m/Y H:i:s' 等，解析失败返回 null */
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
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd-m-Y H:i:s',
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

    /**
     * 按候选键顺序读取首个子串字段值（字符串或数值），全部缺失返回空串。
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }
}
