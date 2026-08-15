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
 * Emirates Post（阿联酋邮政）国际件查询（官方 EMX API GetTrackDetails，GET +
 * AccountNo/Password）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 EMX 开发者门户文档，需实网验证（生产环境
 * 端点推断为 os.epservices.ae/ebs（文档示例 osbtest.epservices.ae 为测试环境，
 * 两者路径一致）；AccountNo/Password 需向 EMX 申请；GetTrackDetailsResponse
 * 下的事件数组字段名（推断为 Events[].EventDateTime/EventDescription/
 * EventLocation，本适配器对 Events/Details/TrackingDetails 三种键与
 * DateTime/Date/Time_Stamp 时间字段容错读取）；单号无效时返回码未确认，
 * 按无事件处理）。
 * 文档: https://developers.emx.ae/pickup&delivery.html
 */
final class EmiratesPost implements CarrierInterface
{
    private const ENDPOINT = 'https://os.epservices.ae/ebs/genericapi/booking/rest/GetTrackDetails';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered' => TrackStatus::DELIVERED,
        'returned|return to sender' => TrackStatus::RETURNED,
        'undelivered|failed|on hold|held|exception|damaged|missed|refused' => TrackStatus::EXCEPTION,
        'picked up|ready for pickup|collected|created|booked|accepted' => TrackStatus::PENDING,
        'in transit|transit|received|arrived|departed|sorted|dispatched|shipped|processed|on the way|sent' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('emirates-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'track_id' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
            'AccountNo' => (string) $this->config->get('emirates-post.account_no', ''),
            'Password' => (string) $this->config->get('emirates-post.password', ''),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[EMIRATES-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[EMIRATES-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[EMIRATES-POST] 响应解析失败');
        }

        $details = $result['GetTrackDetailsResponse'] ?? null;
        if (!is_array($details)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 事件数组键名容错：Events / Details / TrackingDetails
        $rawEvents = $details['Events'] ?? $details['Details'] ?? $details['TrackingDetails'] ?? null;
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
            carrierCode: 'emirates-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($details['Status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('emirates-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('emirates-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('emirates-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['EventDescription'] ?? $row['Description'] ?? $row['Status'] ?? '');
        $location = (string) ($row['EventLocation'] ?? $row['Location'] ?? $row['Office'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['EventDateTime'] ?? $row['DateTime'] ?? $row['Date'] ?? $row['Time_Stamp'] ?? null),
            location: $location,
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

    /** 支持 'n/j/Y g:i:s A'（EMX 时间戳格式）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'n/j/Y g:i:s A', 'n/j/Y H:i:s', 'm/d/Y g:i:s A', 'd/m/Y g:i:s A', 'd-m-Y g:i:s A',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d\TH:i:s.uP',
            'Y-m-d', 'd-m-Y', 'd/m/Y',
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
