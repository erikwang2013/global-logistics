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
 * Saudi Post SPL（沙特邮政）国际件查询（官方 SPL 合作方 API，GET + Bearer Token）。
 *
 * VERIFIED-REQUIRED: 契约基于 SPL 官方合作方 API 文档（wiki.partners.splonline.com.sa，
 * LIS APIs，Bearer token 认证 + 客户/网点代码），需实网验证（API 基址与追踪端点
 * 路径由文档模式推断为 api.splonline.com.sa 下 /v1/trackings，具体以 SPL 签约为准；
 * 响应 events 数组字段名（eventDate/eventDescription/location）按文档常见模式
 * 容错读取；单号无效时 events 为空数组，按无事件处理；官方状态码表未公开，
 * 未确认的关键词统一按 IN_TRANSIT 兜底，交付/派送类关键词按 UPU S10 标准文案映射）。
 * 文档: https://wiki.partners.splonline.com.sa/spl-apis/apis-documentation
 */
final class SaudiPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.splonline.com.sa/v1/trackings';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含阿拉伯语）。
     * 'out for delivery' 须先于 'delivered'。
     */
    private const STATUS_MAP = [
        'out for delivery|for delivery|قيد التوصيل' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|تم التسليم|سلمت' => TrackStatus::DELIVERED,
        'returned|return to sender|مرتجع' => TrackStatus::RETURNED,
        'undeliver|failed|held|refused|unclaimed|exception|رفض' => TrackStatus::EXCEPTION,
        'accepted|received|created|ready for pickup|picked up|collected|قبول' => TrackStatus::PENDING,
        'in transit|transit|arrived|departed|sorted|dispatched|shipped|processed|on the way|قيد النقل|في الطريق' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('saudi-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . (string) $this->config->get('saudi-post.key'),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SAUDI-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SAUDI-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SAUDI-POST] 响应解析失败');
        }

        $payload = $result['data'] ?? $result['Data'] ?? $result;
        if (!is_array($payload)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $payload['events'] ?? $payload['Events'] ?? $payload['history'] ?? $payload['History'] ?? $payload['trackingDetails'] ?? null;
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
            carrierCode: 'saudi-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($payload['status'] ?? $payload['Status'] ?? $result['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('saudi-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('saudi-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('saudi-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? $row['EventDescription'] ?? $row['Description'] ?? $row['status'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? $row['EventDate'] ?? $row['Date'] ?? $row['eventTime'] ?? null),
            location: (string) ($row['location'] ?? $row['Location'] ?? $row['city'] ?? ''),
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

    /** 支持 ISO8601（含时区）、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'd/m/Y H:i:s', 'd/m/Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y',
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
