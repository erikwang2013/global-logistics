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
 * Pos Indonesia（印尼邮政）国际件查询（官网公共跟踪接口逆向，POST JSON + barcode，
 * 无认证；响应 data.history 为事件数组，按时间升序返回）。
 *
 * VERIFIED-REQUIRED: 无官方公开 API（官方跟踪仅在 www.posindonesia.co.id/en/tracking
 * 网页与 EMS 门户 ems.posindonesia.co.id 提供），契约基于公开逆向资料（社区对官网
 * "cari kiriman" 表单的逆向，POST JSON {"barcode":"..."}，响应 data.history 事件含
 * date/lokasi/keterangan 等字段，需实网验证；单号无效时 history 为空数组或 data 缺失，
 * 按无事件处理；状态关键词取自官网/EMS 标准描述，未确认字段以通用关键词兜底）。
 * 文档: https://www.posindonesia.co.id/en/tracking
 */
final class PosIndonesia implements CarrierInterface
{
    private const ENDPOINT = 'https://www.posindonesia.co.id/api/cari';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，印尼语/英语）。
     * 'telah diterima'（已签收）须先于 'diterima di'（中转接收）等一般关键词。
     */
    private const STATUS_MAP = [
        'out for delivery|keluar untuk pengantaran|out for physical delivery|pengantaran' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|terkirim|telah diterima|diterima oleh penerima|delivered' => TrackStatus::DELIVERED,
        'returned|return|retur|dikembalikan' => TrackStatus::RETURNED,
        'failed|exception|gagal|tidak dapat|undeliver|rusak|alamat salah|ditolak' => TrackStatus::EXCEPTION,
        'ready for pickup|posting|collection|diposting|siap diambil|diambil|collected' => TrackStatus::PENDING,
        'in transit|in_transit|transit|diterima di|arrived|departed|received|accepted|customs|bea cukai|processing|release|inward|outward|dispatch|dispatched|forwarded|sorting|dikirim|dalam perjalanan' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('pos-indonesia.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['barcode' => $trackingNo], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POS-INDONESIA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POS-INDONESIA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POS-INDONESIA] 响应解析失败');
        }

        $data = $result['data'] ?? null;
        $history = is_array($data) ? ($data['history'] ?? $data['histori'] ?? null) : ($result['history'] ?? $result['histori'] ?? null);
        if (!is_array($history) || $history === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件若按时间降序返回则反转；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'pos-indonesia',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('pos-indonesia createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('pos-indonesia createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('pos-indonesia subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = $this->pick($row, ['keterangan', 'description', 'status']);

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? $row['waktu'] ?? $row['tanggal'] ?? null),
            location: $this->pick($row, ['kantor', 'lokasi', 'location', 'kota']),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s'、'd-m-Y H:i:s' 等，解析失败返回 null */
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
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd/m/Y H:i:s',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
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
