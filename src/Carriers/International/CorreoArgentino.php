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
 * Correo Argentino（阿根廷邮政）国际件查询（官方 PAQ.AR 2.0 API，GET + Apikey/
 * agreement 请求头，JSON body 携带 trackingNumber 列表）。
 *
 * VERIFIED-REQUIRED: 契约基于官方集成文档 apiPaqAr-v2.pdf（api.correoargentino.com.ar/
 * paqar/v1/tracking，GET + Authorization: Apikey {key} + agreement 头，body 为
 * {"trackingNumber": [...]} 数组，响应为每个单号的 events 历史），需实网验证
 * （agreement 与 API Key 需向 Correo Argentino 申请；响应事件字段名
 * event/date/branch/status 按官方示例容错读取；单号无效或未预登记时 events 为空
 * 数组，按无事件处理；状态关键词按官网标准文案映射（Entregado/En tránsito/
 * En reparto 等））。
 * 文档: https://www.correoargentino.com.ar/MiCorreo/public/img/pag/apiPaqAr-v2.pdf
 */
final class CorreoArgentino implements CarrierInterface
{
    private const ENDPOINT = 'https://api.correoargentino.com.ar/paqar/v1/tracking';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含西班牙语）。
     * 'en reparto'（派送中）须先于 'entregado'（"entregado" 交付）。
     */
    private const STATUS_MAP = [
        'out for delivery|en reparto|reparto' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|entregado|entregada' => TrackStatus::DELIVERED,
        'returned|devuelto|retorno|return' => TrackStatus::RETURNED,
        'failed|exception|rechazado|no entregado|incidente|siniestrado|undeliver|caduca' => TrackStatus::EXCEPTION,
        'accepted|recibido|creado|ingresado|en depósito|pendiente|listo para retirar|preimposición' => TrackStatus::PENDING,
        'in transit|en tránsito|en transito|transit|salida|llegada|arribo|clasificado|procesado|despachado|en camino' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('correo-argentino.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint, [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Apikey ' . (string) $this->config->get('correo-argentino.key'),
            'agreement' => (string) $this->config->get('correo-argentino.agreement', ''),
        ], json_encode([
            ['trackingNumber' => $trackingNo],
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREO-ARGENTINO %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREO-ARGENTINO %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CORREO-ARGENTINO] 响应解析失败');
        }

        // 官方返回按单号分组的历史数组；取与查询单号匹配的第一组
        $shipment = null;
        foreach ($result as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['trackingNumber'] ?? '') === $trackingNo || $shipment === null) {
                $shipment = $entry;
            }
            if (($entry['trackingNumber'] ?? '') === $trackingNo) {
                break;
            }
        }
        if (!is_array($shipment)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $shipment['events'] ?? $shipment['Events'] ?? $shipment['history'] ?? $shipment['History'] ?? null;
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
            carrierCode: 'correo-argentino',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($shipment['status'] ?? $shipment['Status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('correo-argentino createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('correo-argentino createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('correo-argentino subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['event'] ?? $row['Event'] ?? $row['EventDescription'] ?? $row['description'] ?? $row['status'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? $row['Date'] ?? $row['eventDate'] ?? null),
            location: (string) ($row['branch'] ?? $row['Branch'] ?? $row['location'] ?? $row['Location'] ?? ''),
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

    /** 支持 ISO8601、'Y-m-d H:i:s'、'd/m/Y H:i:s' 等，解析失败返回 null */
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

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y',
        ] as $format) {
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
