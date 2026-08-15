<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

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
 * 安能物流（ANE）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（端点 https://open.ane56.com/api/track/query、appKey 签名参数形态与轨迹字段名）。
 */
final class Ane implements CarrierInterface
{
    private const ENDPOINT = 'https://open.ane56.com/api/track/query';

    /** 安能状态关键词 => 统一状态（以 desc 内容匹配，`|` 分隔同义关键词） */
    private const STATUS_MAP = [
        '签收' => TrackStatus::DELIVERED,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '揽收|收件' => TrackStatus::PENDING,
        '到达|出发|运输|中转|分拨' => TrackStatus::IN_TRANSIT,
        '退回' => TrackStatus::RETURNED,
        '异常|滞留' => TrackStatus::EXCEPTION,
    ];

    private const TIME_FIELD = 'time';
    private const LOCATION_FIELD = 'location';
    private const DESC_FIELD = 'desc';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appKey = (string) $this->config->get('ane.app_key');
        $timestamp = (string) time();
        $sign = md5($appKey . $timestamp);

        $body = json_encode([
            'trackingNo' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
            'appKey' => $appKey,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ], $body);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[ANE %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[ANE %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[ANE] 响应解析失败');
        }

        $code = (string) ($result['code'] ?? '');
        if ((int) ($result['code'] ?? 0) !== 200) {
            $this->throwForApiError($code, (string) ($result['msg'] ?? ''));
        }

        $traces = $result['data']['list'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新；若承运商返回降序则反转
        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        if (count($events) > 1
            && $events[0]->occurredAt !== null
            && $events[count($events) - 1]->occurredAt !== null
            && $events[0]->occurredAt > $events[count($events) - 1]->occurredAt) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ane',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $code,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('ANE createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ANE createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ANE subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row[self::DESC_FIELD] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keywords => $mapped) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($description, $keyword)) {
                    $status = $mapped;
                    break 2;
                }
            }
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row[self::TIME_FIELD] ?? '')),
            location: (string) ($row[self::LOCATION_FIELD] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', '10001', '10002', '10003'], true)) {
            throw new AuthException(sprintf('[ANE %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[ANE %s] %s', $code, $message));
    }
}
