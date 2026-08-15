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
 * Correos（西班牙邮政）国际件查询（Trackpub API，client_id/client_secret 请求头）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（api1.correos.es 网关路径、
 * client_id/client_secret 头与 /search 响应字段名按 Correos Developer Portal Trackpub
 * swagger（Mulesoft）推断；事件码 DLVD/OFD/ADMD 对应 Matriz de Eventos 标准矩阵）。
 * 文档: https://www.correos.es/es/en/companies/e-commerce/reinforce-your-ecommerce-logistics/api-integration
 */
final class Correos implements CarrierInterface
{
    private const ENDPOINT = 'https://api1.correos.es/trackpub/api/v1/search/{trackingNo}';

    /**
     * 事件描述/代码关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含西班牙语）。
     */
    private const STATUS_MAP = [
        'delivered|entregado|entregada|repartido|dlvd' => TrackStatus::DELIVERED,
        'out for delivery|reparto|distribution|ofd' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|devuelto|devuelta|rechazado|return|devolución' => TrackStatus::RETURNED,
        'exception|incidencia|failed|rejected|anomalía|attempt' => TrackStatus::EXCEPTION,
        'admitted|admitido|accepted|received|information received|registrado|admd' => TrackStatus::PENDING,
        'in transit|transito|tránsito|en transito|arrived|departed|llegado|salida|clasificado|tran' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = str_replace(
            '{trackingNo}',
            rawurlencode($trackingNo),
            (string) $this->config->get('correos.endpoint', self::ENDPOINT),
        );

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint, [
            'client_id' => (string) $this->config->get('correos.client_id'),
            'client_secret' => (string) $this->config->get('correos.client_secret'),
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREOS %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREOS %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CORREOS] 响应解析失败');
        }

        $rawEvents = $result['events'] ?? [];
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
            carrierCode: 'correos',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['code'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('correos createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('correos createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('correos subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $code = (string) ($row['code'] ?? '');
        $description = (string) ($row['description'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: (string) ($row['location'] ?? ''),
            description: $description !== '' ? $description : $code,
            status: $this->mapStatus($description . ' ' . $code),
            raw: $row,
        );
    }

    private function mapStatus(string $text): TrackStatus
    {
        $text = strtolower($text);
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
