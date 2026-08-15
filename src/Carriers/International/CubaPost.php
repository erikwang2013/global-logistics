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
 * Correos de Cuba（古巴邮政）国际件查询（官方公开跟踪 Web 页面，GET + codigo 参数，
 * 无认证；未开放公开 JSON 端点，故按页面表格解析：Date | Office | [City] | Description）。
 *
 * VERIFIED-REQUIRED: 契约基于公开网页资料（官方跟踪页面 https://www.correos.cu/），需实网验证
 * （查询参数名与表格列数以官网实际输出为准；日期无时间部分时按当日零点处理；
 * 单号无效时页面无数据行，按无事件处理；状态关键词为官网西班牙语/英语标准描述，
 * 查不到的字段按 IN_TRANSIT 兜底）。
 * 文档: https://www.correos.cu/
 */
final class CubaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.correos.cu/';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含西班牙语/英语）。
     * 'out for delivery'/'en reparto' 须先于 'delivered'/'entregado'；
     * 'no entregado'/'undeliver'（EXCEPTION）须先于 'entregado'/'delivered'。
     */
    private const STATUS_MAP = [
        'out for delivery|salio a distribucion|salió a distribución|salio a reparto|en reparto|en distribucion|en distribución|para entrega|delivery to addressee|entrega programada|scheduled delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|return to sender|devuelto|devolucion|devolución|retorno al remitente|regreso al remitente|rechazado' => TrackStatus::RETURNED,
        'undeliver|failed|intento de entrega fallido|no entregado|held|unclaimed|no reclamado|abandoned|missing|refused|exception|excepcion|excepción' => TrackStatus::EXCEPTION,
        'delivered|entregado|entregada|fue entregado|entrega realizada|successfully delivered' => TrackStatus::DELIVERED,
        'accepted|recibido|admitido|registrado|created|collected|picked up|ready for pickup|disponible para retiro|pendiente de entrega|en espera|waiting|aviso dejado' => TrackStatus::PENDING,
        'in transit|transit|transito|tránsito|arrived|llego|llegó|llegada|departed|salio|salió|salida|sorted|clasificado|dispatched|despachado|forwarded|procesado|processed|customs|aduana|inbound|outbound|in transport|en transporte' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('cuba-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'codigo' => $trackingNo,
        ]), [
            'Accept' => 'text/html, application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CUBA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CUBA-POST %s] 接口错误', $status));
        }

        $html = (string) $response->getBody();
        // 响应若为 JSON（如网关错误包装）且非对象/数组，按解析失败处理
        $decoded = json_decode($html, true);
        if ($decoded !== null && !is_array($decoded)) {
            throw new LogisticsException('[CUBA-POST] 响应解析失败');
        }

        $events = [];
        foreach ($this->extractRows($html) as $row) {
            if ($row === []) {
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
            carrierCode: 'cuba-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['description'] ?? '',
            raw: ['html' => $html],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('cuba-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('cuba-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('cuba-post subscribe 待实现');
    }

    /**
     * 从跟踪页 HTML 中提取表格行（Date | Office | [City] | Description，
     * 跳过表头行；容错：未匹配到 4 列时尝试 3 列 Date | Location | Description）。
     *
     * @return array<int, array{date: string, unit: string, city: string, description: string}>
     */
    private function extractRows(string $html): array
    {
        $rows = [];
        if (!preg_match_all('~<tr[^>]*>(.*?)</tr>~si', $html, $rowMatches, PREG_SET_ORDER)) {
            return $rows;
        }
        foreach ($rowMatches as $rowMatch) {
            if (!preg_match_all('~<td[^>]*>(.*?)</td>~si', $rowMatch[1], $cellMatches, PREG_SET_ORDER) || count($cellMatches) < 3) {
                continue;
            }
            $cells = array_map(static fn (array $c): string => trim(strip_tags($c[1])), $cellMatches);
            $date = $cells[0];
            $dateLower = function_exists('mb_strtolower') ? mb_strtolower($date, 'UTF-8') : strtolower($date);
            if (in_array($dateLower, ['date', 'fecha', 'дата', 'датум', 'datum', 'data', 'ημερομηνία', 'fecha/hora', 'date/time'], true)) {
                continue; // 表头行
            }
            $rows[] = isset($cells[3])
                ? ['date' => $date, 'unit' => $cells[1], 'city' => $cells[2], 'description' => $cells[3]]
                : ['date' => $date, 'unit' => $cells[1], 'city' => '', 'description' => $cells[2]];
        }

        return $rows;
    }

    /**
     * @param array{date: string, unit: string, city: string, description: string} $row
     */
    private function mapEvent(array $row): TrackingEvent
    {
        $location = trim($row['unit'] . ($row['city'] !== '' ? ', ' . $row['city'] : ''));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date']),
            location: $location,
            description: $row['description'],
            status: $this->mapStatus($row['description']),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($description, 'UTF-8') : strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持官网日期格式（无时间部分）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒（仅限 ISO8601 时间戳，避免误伤纯日期）
        if (str_contains($raw, 'T') && preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'Y-m-d\\TH:i:s.vP', 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y-m-d'] as $format) {
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