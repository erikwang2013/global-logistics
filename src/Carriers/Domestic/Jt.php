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

final class Jt implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.jtexpress.cn/API/External_GetTraces.json';

    /** 极兔原始状态词 => 统一状态 */
    private const STATUS_MAP = [
        '已揽收' => TrackStatus::PENDING,
        '运输中' => TrackStatus::IN_TRANSIT,
        '派送中' => TrackStatus::OUT_FOR_DELIVERY,
        '已签收' => TrackStatus::DELIVERED,
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
        $timestamp = (string) time();
        $sign = md5($this->config->get('jt.secret', '') . $timestamp);

        $body = json_encode([
            'sign' => $sign,
            'timestamp' => $timestamp,
            'msg_type' => 'GET_TRACES',
            'data' => [
                'tracking_number' => $trackingNo,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
            'ApiKey' => (string) $this->config->get('jt.api_key'),
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[JT] 响应解析失败');
        }

        if (($result['success'] ?? false) !== true) {
            $this->throwForApiError((string) ($result['code'] ?? ''), (string) ($result['message'] ?? ''));
        }

        $item = $result['data'][0] ?? [];
        $traces = is_array($item) ? ($item['traces'] ?? []) : [];
        if ($traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $traces);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'jt',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('JT createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('JT createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('JT subscribe 待实现（极兔推送服务开通后按文档接入）');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['track_time'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['station_name'] ?? ''),
            description: (string) ($row['track_desc'] ?? ''),
            status: self::STATUS_MAP[(string) ($row['status'] ?? '')] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', '50001'], true)) {
            throw new AuthException(sprintf('[JT %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[JT %s] %s', $code, $message));
    }
}
