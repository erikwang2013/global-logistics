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

final class Yd implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.yundaex.com/erpApi/traceQuery';

    /** 韵达状态关键词 => 统一状态（以 trackDesc 内容匹配） */
    private const STATUS_MAP = [
        '揽收' => TrackStatus::PENDING,
        '运输' => TrackStatus::IN_TRANSIT,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
        '签收' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $body = json_encode([
            'trackingNumber' => $trackingNo,
            'format' => 'json',
        ], JSON_UNESCAPED_UNICODE);

        $appKey = (string) $this->config->get('yd.app_key');
        $secret = (string) $this->config->get('yd.app_secret');
        $timestamp = (string) time();
        $sign = md5('app_key' . $appKey . 'timestamp' . $timestamp . $secret);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query([
            'app_key' => $appKey,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]), [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[YD] 响应解析失败');
        }

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['msg'] ?? ''));
        }

        $traces = $result['data'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
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
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yd',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['trackDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('YD createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('YD createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('YD subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['trackTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['trackDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['stationName'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['4001', '4002', '4003'], true)) {
            throw new AuthException(sprintf('[YD %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[YD %s] %s', $code, $message));
    }
}
