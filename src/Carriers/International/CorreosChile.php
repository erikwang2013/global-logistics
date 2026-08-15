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
 * CorreosChile（智利邮政）国际件查询（官方开发者 API v2 Trazabilidad，GET + Authorization
 * token，响应 JSON 的 historial 为事件数组，官方按日期降序返回）。
 *
 * VERIFIED-REQUIRED: 契约基于官方文档（developers.correos.cl/v2/trazabilidad，示例环境
 * cert-apib2bv2.correos.cl:8000，生产基础 URL 推断为 apib2bv2.correos.cl:8443，需实网
 * 验证；Authorization 支持 "basic base64(user:pass)" 或 "token <TOKEN>"，本适配器采用
 * token 形式；historial 事件字段 fecha（ISO 8601 / d-m-YTH:i:s）、estado、oficina、
 * estadoBase（官方 seguimiento-en-linea 页面配置 codsEntregado=006,010、
 * codsNoEntregado=007,011、codsEnCurso=003,005,008,004，即 006/010=已交付、
 * 007/011=未交付，其余=在途，本适配器以 estadoBase 优先、estado 文本兜底）；
 * 单号无记录时官方返回 HTTP 400 或空 historial，按无事件处理）。
 * 文档: https://developers.correos.cl/v2/trazabilidad
 */
final class CorreosChile implements CarrierInterface
{
    private const ENDPOINT = 'https://apib2bv2.correos.cl:8443/trazabilidad/';

    /**
     * estado（西班牙语）关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'en reparto'（派送中）须先于 'entregad'（已交付）等一般关键词。
     */
    private const STATUS_MAP = [
        'out for delivery|en reparto|salida a reparto|en ruta de reparto' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|entregad|entregado al destinatario' => TrackStatus::DELIVERED,
        'returned|return|devoluc|devuelt' => TrackStatus::RETURNED,
        'failed|exception|no pudo ser entregado|no hay quien reciba|direccion de entrega insuficiente|insuficiente|rechazad|contactar servicio|siniestr|averiad|extraviad' => TrackStatus::EXCEPTION,
        'ready for pickup|pendiente de entrega|pendiente|disponible para retiro|en sucursal' => TrackStatus::PENDING,
        'in transit|in_transit|en preparacion|preparacion|despachad|recibid|en transito|transito|gestionad|clasificad|aduan|planta|salida|ingreso|procesad|admisi' => TrackStatus::IN_TRANSIT,
    ];

    /** estadoBase 官方码 => 统一状态（优先于文本匹配） */
    private const BASE_STATUS_MAP = [
        '006' => TrackStatus::DELIVERED,
        '010' => TrackStatus::DELIVERED,
        '007' => TrackStatus::EXCEPTION,
        '011' => TrackStatus::EXCEPTION,
        '003' => TrackStatus::IN_TRANSIT,
        '004' => TrackStatus::IN_TRANSIT,
        '005' => TrackStatus::IN_TRANSIT,
        '008' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('correos-chile.endpoint', self::ENDPOINT);
        $token = (string) $this->config->get('correos-chile.token', '');
        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'token ' . $token;
        }
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . $trackingNo, $headers);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREOS-CHILE %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREOS-CHILE %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CORREOS-CHILE] 响应解析失败');
        }

        $historial = $result['historial'] ?? null;
        if (!is_array($historial) || $historial === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($historial as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'correos-chile',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['estadoBase'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('correos-chile createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('correos-chile createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('correos-chile subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = $this->pick($row, ['estado', 'descripcion']);
        $status = $this->mapBaseStatus((string) ($row['estadoBase'] ?? ''));
        if ($status === TrackStatus::UNKNOWN) {
            $status = $this->mapStatus($description);
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['fecha'] ?? null),
            location: $this->pick($row, ['oficina', 'lugar', 'ciudad']),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function mapBaseStatus(string $code): TrackStatus
    {
        if ($code !== '' && isset(self::BASE_STATUS_MAP[$code])) {
            return self::BASE_STATUS_MAP[$code];
        }

        return TrackStatus::UNKNOWN;
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'd-m-YTH:i:s'（官方示例格式）、'd-m-Y H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒（至少 6 位数字，避免误伤 d.m.Y 点分日期）
        if (preg_match('/^(.+\.)(\d{6,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'd-m-Y\TH:i:s', 'd-m-Y\TH:i',
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd/m/Y H:i:s', 'd-m-Y g:i:s A',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d-m-Y', '!d/m/Y', '!Y-m-d',
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

    /**
     * 按候选键顺序读取首个子串字段值（字符串或数值），全部缺失返回空串。
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }
}
