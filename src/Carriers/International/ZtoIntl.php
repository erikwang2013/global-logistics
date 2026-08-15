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
 * 中通国际（ZTO International）国际件查询（中通国际开放平台 Track API，POST JSON + MD5 签名）。
 *
 * VERIFIED-REQUIRED: 契约基于国内中通 openapi 的 companyId/dataDigest 签名模式推断，
 * 需实网验证（国际开放平台端点 openapi-global.zto.com、响应 data.traces 事件字段
 * date/desc/status 与国内一致为文档推断；单号与国内中通同为纯 13 位数字，默认仅显式调用）。
 * 文档: https://global.zto.com/
 */
final class ZtoIntl implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi-global.zto.com/trace/queryTrack';

    /**
     * 状态文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，中文 + 英文）。
     * '派送中' 与 '已签收' 无前缀重叠，但统一将更具体状态放在前面。
     */
    private const STATUS_MAP = [
        '已揽收|picked up|picked_up' => TrackStatus::PENDING,
        '运输中|in transit|in_transit|transit' => TrackStatus::IN_TRANSIT,
        '派送中|out for delivery|out_for_delivery' => TrackStatus::OUT_FOR_DELIVERY,
        '已签收|delivered|signed' => TrackStatus::DELIVERED,
        '异常|exception|failed' => TrackStatus::EXCEPTION,
        '退回|returned|return' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('zto-intl.endpoint', self::ENDPOINT);
        $companyId = (string) $this->config->get('zto-intl.company_id');
        $secret = (string) $this->config->get('zto-intl.secret', '');

        $data = json_encode(['billNo' => $trackingNo], JSON_UNESCAPED_UNICODE);
        $body = json_encode([
            'companyId' => $companyId,
            'msgType' => 'TRACK',
            'data' => $data,
            'dataDigest' => md5($data . $secret),
            'timestamp' => (string) time(),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ZTO-INTL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ZTO-INTL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[ZTO-INTL] 响应解析失败');
        }

        $code = (string) ($result['status'] ?? '');
        if ($code !== '200' && $code !== '') {
            throw new LogisticsException(sprintf('[ZTO-INTL %s] %s', $code, (string) ($result['message'] ?? '')));
        }

        $rawEvents = $result['data']['traces'] ?? [];
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
            carrierCode: 'zto-intl',
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
        throw new LogisticsException('zto-intl createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('zto-intl createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('zto-intl subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: (string) ($row['desc'] ?? ''),
            status: $this->mapStatus((string) ($row['status'] ?? '') . ' ' . (string) ($row['desc'] ?? '')),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s' 等，解析失败返回 null */
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

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
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
