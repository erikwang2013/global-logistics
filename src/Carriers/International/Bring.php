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
 * Bring（挪威邮政）国际件查询（官方 tracking API，JSON API 格式，GET + q 参数）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（2025 年起公开端点
 * 已要求认证，需在 Mybring 开发者门户注册后提供 API key 请求头；
 * consignmentSet/packageSet/eventSet 结构与 eventSet 字段名为文档推断）。
 * 文档: https://developer.bring.com/api/tracking/
 */
final class Bring implements CarrierInterface
{
    private const ENDPOINT = 'https://tracking.bring.com/api/v2/tracking.json';

    /**
     * description/status 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'delivered' => TrackStatus::DELIVERED,
        'out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|returning' => TrackStatus::RETURNED,
        'failed|exception|held|unclaimed|abandoned' => TrackStatus::EXCEPTION,
        'picked up|ready for pickup|prepared|registered' => TrackStatus::PENDING,
        'in transit|transit|received|arrived|departed|sorted|forwarded' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('bring.endpoint', self::ENDPOINT);
        $headers = ['Accept' => 'application/json'];
        // 可选认证：在 Mybring 门户注册后提供；未配置则走公开端点
        $apiKey = (string) $this->config->get('bring.api_key', '');
        $clientUrl = (string) $this->config->get('bring.client_url', '');
        if ($apiKey !== '') {
            $headers['X-MyBring-API-Key'] = $apiKey;
            $headers['X-Bring-Client-URL'] = $clientUrl !== '' ? $clientUrl : 'https://example.com/';
        }

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'q' => $trackingNo,
        ]), $headers);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[BRING %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[BRING %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[BRING] 响应解析失败');
        }

        $events = [];
        foreach (($result['consignmentSet'] ?? []) as $consignment) {
            if (!is_array($consignment)) {
                continue;
            }
            foreach (($consignment['packageSet'] ?? []) as $package) {
                if (!is_array($package)) {
                    continue;
                }
                foreach (($package['eventSet'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $events[] = $this->mapEvent($row);
                }
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'bring',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('bring createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('bring createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('bring subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $text = (string) ($row['description'] ?? '');
        $unitInfo = $row['unitInformation'] ?? null;
        $unitStatus = '';
        if (is_array($unitInfo)) {
            $first = $unitInfo[0] ?? null;
            if (is_array($first)) {
                $unitStatus = (string) ($first['status'] ?? '');
            }
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dateAndTime'] ?? null),
            location: (string) ($row['unitId'] ?? ''),
            description: $text,
            status: $this->mapStatus($text . ' ' . $unitStatus),
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

    /** 支持 ISO8601 带时区（含毫秒/时区偏移）、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
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
