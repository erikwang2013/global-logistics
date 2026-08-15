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
 * 新加坡邮政（SINGAPORE-POST）适配器。
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（api.tracking.singpost.com
 * 为 SingPost Track & Trace API 商业入口，api-key 头与 trackingInfo/trackingEvents
 * 响应结构为公开集成文档推断，注册需联系 SingPost 客户经理）。
 */
final class SingaporePost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.tracking.singpost.com/v2/track';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
            'api-key' => (string) $this->config->get('singapore-post.api_key'),
        ], json_encode(['trackingNumber' => [$trackingNo]], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SINGAPORE-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SINGAPORE-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SINGAPORE-POST] 响应解析失败');
        }
        if (isset($result['errors']) && is_array($result['errors']) && $result['errors'] !== []) {
            throw new LogisticsException('[SINGAPORE-POST] ' . $this->firstErrorMessage($result['errors']));
        }

        $trackingInfo = $result['data']['trackingInfo'] ?? null;
        $info = is_array($trackingInfo) ? ($trackingInfo[0] ?? null) : null;
        if (!is_array($info) || !is_array($info['trackingEvents'] ?? null)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($info['trackingEvents'] as $row) {
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
            carrierCode: 'singapore-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($info['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('SINGAPORE-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('SINGAPORE-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('SINGAPORE-POST subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? $row['status'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dateTime'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = strtolower($description);

        if (str_contains($text, 'delivered')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'out for delivery')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'return')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'exception') || str_contains($text, 'failed') || str_contains($text, 'held')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'transit') || str_contains($text, 'accept') || str_contains($text, 'arriv')) {
            return TrackStatus::IN_TRANSIT;
        }
        if (str_contains($text, 'received') || str_contains($text, 'collected') || str_contains($text, 'picked up')) {
            return TrackStatus::PENDING;
        }

        return TrackStatus::UNKNOWN;
    }

    /**
     * 多格式时间解析：ISO8601 带时区偏移（含 Z 与毫秒）、无偏移、空格分隔；全部失败返回 null。
     */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
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
     * @param array<int, array<string, mixed>> $errors
     */
    private function firstErrorMessage(array $errors): string
    {
        $first = $errors[0] ?? [];
        if (is_array($first)) {
            $message = (string) ($first['message'] ?? $first['detail'] ?? '');
            if ($message !== '') {
                return $message;
            }
        }

        return '业务错误';
    }
}
