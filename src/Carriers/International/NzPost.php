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
 * 新西兰邮政（NZ-POST）适配器。
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（NZ Post legacy Track API，
 * license_key 需在 NZ Post Developer Centre 申请；JSON 响应以单号为键的
 * 对象结构、flag 事件码均为官方 Track method 文档定义）。
 * 文档: https://www.nzpost.co.nz/business/developer-centre/nz-post-legacy-apis/tracking-api/track-method
 */
final class NzPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.nzpost.co.nz/tracking/track';

    /** 官方 flag 事件码 => 统一状态（A/C/O/F/D 为文档定义） */
    private const FLAG_MAP = [
        'A' => TrackStatus::PENDING,
        'B' => TrackStatus::IN_TRANSIT,
        'C' => TrackStatus::IN_TRANSIT,
        'D' => TrackStatus::EXCEPTION,
        'F' => TrackStatus::DELIVERED,
        'O' => TrackStatus::OUT_FOR_DELIVERY,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'license_key' => (string) $this->config->get('nz-post.license_key'),
            'user_ip_address' => (string) $this->config->get('nz-post.user_ip_address', '127.0.0.1'),
            'tracking_code' => $trackingNo,
            'format' => 'json',
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[NZ-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[NZ-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[NZ-POST] 响应解析失败');
        }
        if (isset($result['message'])) {
            throw new LogisticsException('[NZ-POST] ' . (string) $result['message']);
        }

        $entry = $this->entryFor($result, $trackingNo);
        if ($entry === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($entry['events'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'nz-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($entry['short_description'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('NZ-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('NZ-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('NZ-POST subscribe 待实现');
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private function entryFor(array $result, string $trackingNo): ?array
    {
        if (isset($result[$trackingNo]) && is_array($result[$trackingNo])) {
            return $result[$trackingNo];
        }
        $upper = strtoupper($trackingNo);
        foreach ($result as $key => $value) {
            if (is_array($value) && strtoupper((string) $key) === $upper) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $flag = strtoupper((string) ($row['flag'] ?? ''));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['datetime'] ?? null, (string) ($row['date'] ?? ''), (string) ($row['time'] ?? '')),
            location: (string) ($row['description'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            status: $this->mapStatus($flag, (string) ($row['description'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $flag, string $description): TrackStatus
    {
        if (isset(self::FLAG_MAP[$flag])) {
            return self::FLAG_MAP[$flag];
        }
        $text = strtolower($description);
        if (str_contains($text, 'deliver')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'return')) {
            return TrackStatus::RETURNED;
        }

        return TrackStatus::UNKNOWN;
    }

    /**
     * 多格式时间解析：ISO8601 带时区偏移（如 "2011-10-11T06:30:00+13:00"），
     * 以及 "26/10/11" + "12.00 AM" 的日期时间分离格式。
     */
    private function parseTime(mixed $datetime, string $date, string $time): ?\DateTimeImmutable
    {
        if (is_string($datetime) && $datetime !== '') {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat($format, $datetime);
                if ($dt !== false) {
                    return $dt;
                }
            }
        }
        if ($date !== '') {
            $dt = \DateTimeImmutable::createFromFormat('d/m/y g.i A', trim($date . ' ' . $time));
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
