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
 * 奥地利邮政（POST.AT）国际件查询（Customer Services API GetParcelDetail，POST + JSON）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（请求体字段名、
 * RawData 内事件结构与 LastEventDate 格式均为公开文档推断，凭据需商户账号）。
 * 文档: https://www.post.at/sendungsverfolgung
 */
final class AustrianPost implements CarrierInterface
{
    private const ENDPOINT = 'https://customerservices.post.at/api/v1/GetParcelDetail?format=json';

    /**
     * 状态文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'zugestellt'（已投递）必须先于 'zustellung'（派送中，前缀重叠）。
     */
    private const STATUS_MAP = [
        'zugestellt|delivered' => TrackStatus::DELIVERED,
        'auslieferung|out for delivery|zustellung' => TrackStatus::OUT_FOR_DELIVERY,
        'retour|return' => TrackStatus::RETURNED,
        'problem|failed|exception|unzustell|abholung' => TrackStatus::EXCEPTION,
        'abgeholt|collected|eingeliefert|posted|ready for shipment' => TrackStatus::PENDING,
        'transport|transit|sortier|angekommen|unterwegs|in transit' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('austrian-post.endpoint', self::ENDPOINT);
        $userName = (string) $this->config->get('austrian-post.user_name');
        $password = (string) $this->config->get('austrian-post.password');

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($userName !== '' || $password !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($userName . ':' . $password);
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, $headers, json_encode([
            'TrackingNumber' => $trackingNo,
            'UserName' => $userName,
            'Password' => $password,
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[AUSTRIAN-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[AUSTRIAN-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[AUSTRIAN-POST] 响应解析失败');
        }
        if (isset($result['message'], $result['statusCode']) && is_scalar($result['message'])) {
            throw new LogisticsException('[AUSTRIAN-POST] ' . (string) $result['message']);
        }
        if (($result['FoundTracking'] ?? null) !== true) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        $rawData = $result['RawData'] ?? null;
        $rawEvents = is_array($rawData) ? ($rawData['events'] ?? $rawData) : [];
        if (is_array($rawEvents) && $rawEvents !== []) {
            foreach ($rawEvents as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            // 顶层仅给最新状态摘要时，退化为单事件
            $events[] = new TrackingEvent(
                occurredAt: $this->parseTime($result['LastEventDate'] ?? null),
                location: (string) ($result['LastEventLocation'] ?? ''),
                description: (string) ($result['StatusDescription'] ?? $result['DGShippingStatus'] ?? ''),
                status: $this->mapStatus((string) ($result['DGShippingStatus'] ?? '') . ' ' . (string) ($result['StatusDescription'] ?? '')),
                raw: $result,
            );
        }
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'austrian-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['DGShippingStatus'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('austrian-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('austrian-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('austrian-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $status = (string) ($row['status'] ?? '');
        $description = (string) ($row['description'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventDate'] ?? $row['date'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: $description !== '' ? $description : $status,
            status: $this->mapStatus($status . ' ' . $description),
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

    /** 支持 ISO8601 带时区、'Y-m-d H:i:s' 等，解析失败返回 null */
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
