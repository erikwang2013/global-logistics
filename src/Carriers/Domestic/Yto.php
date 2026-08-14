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

final class Yto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.yto.com/openapi/queryTrace';

    /** 圆通状态关键词 => 统一状态（以 remark 内容匹配） */
    private const STATUS_MAP = [
        '已揽收' => TrackStatus::PENDING,
        '运输中' => TrackStatus::IN_TRANSIT,
        '派送中' => TrackStatus::OUT_FOR_DELIVERY,
        '签收' => TrackStatus::DELIVERED,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $body = json_encode([
            'logisticProviderID' => 'YTO',
            'trackingNumber' => $trackingNo,
            'queryType' => '1',
        ], JSON_UNESCAPED_UNICODE);

        $appKey = (string) $this->config->get('yto.app_key');
        $secret = (string) $this->config->get('yto.app_secret');
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
            throw new LogisticsException('[YTO] 响应解析失败');
        }

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['message'] ?? ''));
        }

        $traces = $result['trace'] ?? [];
        if ($traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $traces);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yto',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['remark'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('YTO createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('YTO createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('YTO subscribe 待实现（圆通推送服务开通后按文档接入）');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['acceptTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['remark'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['acceptAddress'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['10001', '10002', '10003'], true)) {
            throw new AuthException(sprintf('[YTO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[YTO %s] %s', $code, $message));
    }
}
