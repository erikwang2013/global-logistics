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

final class Sto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.sto.cn/erpapi/traceQuery';

    /** 申通状态关键词 => 统一状态（以 scanDesc 内容匹配） */
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
        // 请求无需签名，customer_id 暂未用于请求体，保留以维持类形状统一
        $this->config->get('sto.customer_id');
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $body = json_encode([
            'waybillNo' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[STO] 响应解析失败');
        }

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['msg'] ?? ''));
        }

        $traces = $result['data']['traces'] ?? [];
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
            carrierCode: 'sto',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['scanDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('STO createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('STO createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('STO subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['scanTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['scanDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['4001', '4002', '4003'], true)) {
            throw new AuthException(sprintf('[STO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[STO %s] %s', $code, $message));
    }
}
