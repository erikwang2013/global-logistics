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
 * 燕文物流（跨境直邮/专线）适配器。
 *
 * VERIFIED-REQUIRED: 燕文官方开放平台（open.yw56.com.cn）文档未公开轨迹接口的
 * 完整报文结构；operation/data 结构、Basic 认证拼接（customer-code & API-secret）、
 * 事件字段名按行业 EDI 模式推断，需开通制单账号后实网验证。
 * 文档: https://open.yw56.com.cn （燕文客户中心开放 API）
 */
final class Yanwen implements CarrierInterface
{
    private const ENDPOINT = 'https://open.yw56.com.cn/api/order';

    /**
     * 事件 description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        'delivered|已签收|签收' => TrackStatus::DELIVERED,
        'out for delivery|派送中|派件中' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|退回|退件' => TrackStatus::RETURNED,
        'failed|exception|attempted|held|异常|滞留|拒收' => TrackStatus::EXCEPTION,
        'collected|received|揽收|已收件' => TrackStatus::PENDING,
        'in transit|transit|arrived|departed|sorted|运输中|在途|到达|发往|中转' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('yanwen.endpoint', self::ENDPOINT);
        $customerCode = (string) $this->config->get('yanwen.customer_code');
        $apiSecret = (string) $this->config->get('yanwen.api_secret');

        // 行业 EDI 模式：operation 指定业务，data 携带单号；Basic 认证拼接 VERIFIED-REQUIRED
        $body = json_encode([
            'operation' => 'get-tracking',
            'data' => ['trackingNumber' => $trackingNo],
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($customerCode . '&' . $apiSecret),
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[YANWEN %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[YANWEN %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[YANWEN] 响应解析失败');
        }
        if (($result['success'] ?? false) !== true) {
            throw new LogisticsException(sprintf(
                '[YANWEN %s] %s',
                (string) ($result['code'] ?? ''),
                (string) ($result['message'] ?? '接口错误'),
            ));
        }

        $dataResult = $result['data'] ?? [];
        $rawEvents = is_array($dataResult) ? ($dataResult['events'] ?? []) : [];
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
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yanwen',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['statusCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('yanwen createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('yanwen createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('yanwen subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['time'] ?? null),
            location: (string) ($row['location'] ?? ''),
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
}
