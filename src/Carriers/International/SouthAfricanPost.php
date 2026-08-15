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
 * South African Post Office（南非邮政）国际件查询（官方 verifyitem 工具端点，
 * POST 表单 + ItemNumber 参数，无认证）。
 *
 * VERIFIED-REQUIRED: 契约基于南非邮政官网 Track My Parcel 工具
 * （postoffice.co.za/tools/tracktrace.html → verifyitem.aspx，表单字段
 * ItemNumber，官方仅提供网页表单，无公开 JSON API），需实网验证（响应实为 HTML
 * 或需 ASP.NET 会话字段，本适配器按容错 JSON 解析，字段名 events/eventDate/
 * description/location 为推断值；单号无效时 events 为空数组，按无事件处理；
 * 状态关键词按 UPU S10 标准文案映射（Delivered/Out for delivery/In transit 等））。
 * 文档: https://www.postoffice.co.za/tools/tracktrace.html
 */
final class SouthAfricanPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.postoffice.co.za/tools/verifyitem.aspx';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|delivered to addressee|signed for' => TrackStatus::DELIVERED,
        'returned|return to sender|re-exported' => TrackStatus::RETURNED,
        'undeliver|failed|held|refused|unclaimed|damaged|exception' => TrackStatus::EXCEPTION,
        'accepted|received|created|ready for collection|collected|picked up|deposited' => TrackStatus::PENDING,
        'in transit|transit|arrived|departed|sorted|dispatched|forwarded|processed|on route|presented|customs' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('south-african-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'ItemNumber' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SOUTH-AFRICAN-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SOUTH-AFRICAN-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SOUTH-AFRICAN-POST] 响应解析失败');
        }

        $rawEvents = $result['events'] ?? $result['Events'] ?? $result['history'] ?? $result['History'] ?? $result['TrackHistory'] ?? null;
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
            carrierCode: 'south-african-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['status'] ?? $result['currentStatus'] ?? $result['Status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('south-african-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('south-african-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('south-african-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? $row['Description'] ?? $row['EventDescription'] ?? $row['eventDescription'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? $row['EventDate'] ?? $row['Date'] ?? $row['date'] ?? null),
            location: (string) ($row['location'] ?? $row['Location'] ?? $row['EventLocation'] ?? $row['office'] ?? ''),
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

    /** 支持 ISO8601、'Y-m-d H:i:s'、'd/m/Y H:i:s' 等，解析失败返回 null */
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
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y',
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
