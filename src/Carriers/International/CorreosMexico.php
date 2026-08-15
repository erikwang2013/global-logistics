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
 * Correos de México（墨西哥邮政）国际件查询（官方 Seguimiento 门户，GET + guia 参数，
 * 无认证）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 Seguimiento de Envíos 门户
 * （correosdemexico.gob.mx/SSLServicios/SeguimientoEnvio/Seguimiento.aspx，网页表单
 * 需 guia + ejercicio 年份），需实网验证（该门户为 ASPX 网页表单、无公开 JSON API，
 * 本适配器按容错 JSON 解析，事件数组键 eventos/historial、字段 Fecha/Descripcion/
 * Oficina 为推断值；单号无效时 eventos 为空数组，按无事件处理；状态关键词按
 * SEPOMEX 官网标准文案映射（Entregado/En reparto/En tránsito 等））。
 * 文档: https://www.correosdemexico.gob.mx/SSLServicios/SeguimientoEnvio/Seguimiento.aspx
 */
final class CorreosMexico implements CarrierInterface
{
    private const ENDPOINT = 'https://www.correosdemexico.gob.mx/SSLServicios/SeguimientoEnvio/Seguimiento.aspx';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含西班牙语）。
     * 'en reparto'（派送中）须先于 'entregado'（"entregado" 交付）。
     */
    private const STATUS_MAP = [
        'out for delivery|en reparto|reparto' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|entregado|entregado al destinatario' => TrackStatus::DELIVERED,
        'returned|devuelto|retorno|return' => TrackStatus::RETURNED,
        'failed|exception|rechazado|no entregado|siniestrado|dañado|undeliver' => TrackStatus::EXCEPTION,
        'accepted|recibido|creado|en almacén|pendiente|listo para recoger|recolectado|aceptada' => TrackStatus::PENDING,
        'in transit|en tránsito|en ruta|transit|arribó|llegó|despachado|clasificado|procesado|salida' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('correos-mexico.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'guia' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREOS-MEXICO %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREOS-MEXICO %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CORREOS-MEXICO] 响应解析失败');
        }

        $rawEvents = $result['eventos'] ?? $result['Eventos'] ?? $result['historial'] ?? $result['History'] ?? null;
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
            carrierCode: 'correos-mexico',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['estatus'] ?? $result['Estatus'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('correos-mexico createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('correos-mexico createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('correos-mexico subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['Descripcion'] ?? $row['descripcion'] ?? $row['Description'] ?? $row['Estatus'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['Fecha'] ?? $row['fecha'] ?? $row['Date'] ?? null),
            location: (string) ($row['Oficina'] ?? $row['oficina'] ?? $row['Location'] ?? ''),
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

    /** 支持 'd/m/Y H:i:s'（官网格式）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y g:i:s A', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            '!d/m/Y', '!d-m-Y', '!Y-m-d',
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
