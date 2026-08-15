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
 * CroatiaPost（克罗地亚邮政）国际件查询（官网 posiljka.posta.hr 跟踪组件 JSON 接口，
 * POST JSON + barcode，无认证；响应 data.events 为事件数组，按时间升序返回）。
 *
 * VERIFIED-REQUIRED: 官方 DXWebAPI（dxwebapi.posta.hr）为签约制 API（需认证 JWT，文档
 * posta.hr/hp-shipping-service-api），本适配器采用官网 posiljka.posta.hr 公共跟踪组件的
 * 逆向契约（社区资料表明 POST JSON {"barcode":"..."}，响应 data.events 事件含 date/
 * location/status 等字段，需实网验证；单号无效时 events 为空数组或 data 缺失，按无事件
 * 处理；状态关键词为官网克罗地亚语标准描述，未确认字段以通用关键词兜底）。
 * 文档: https://posiljka.posta.hr/en
 */
final class CroatiaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://posiljka.posta.hr/api/track';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，克罗地亚语/英语）。
     * 'u dostavi'（派送中）须先于 'dostavljen'（已交付）等一般关键词。
     */
    private const STATUS_MAP = [
        'out for delivery|u dostavi|dostava u tijeku|kurirska dostava|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|dostavljen|uručen|delivered' => TrackStatus::DELIVERED,
        'returned|return|povrat|vraćen|vracen' => TrackStatus::RETURNED,
        'failed|exception|neuspješna|nije dostavljen|odbijeno|oštećen|ne preuzet|nenadoknadiv' => TrackStatus::EXCEPTION,
        'ready for pickup|spreman za preuzimanje|preuzimanje|zadržan|cek|pending' => TrackStatus::PENDING,
        'in transit|in_transit|tranzit|zaprimljen|primljen|otpremljen|prispio|sortiran|obrad|u obradi|isporuč|posiljka|transit|arrived|departed|accepted|processed' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('croatia-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['barcode' => $trackingNo], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CROATIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CROATIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CROATIA-POST] 响应解析失败');
        }

        $data = $result['data'] ?? null;
        $events = is_array($data) ? ($data['events'] ?? $data['history'] ?? $data['items'] ?? null) : ($result['events'] ?? $result['history'] ?? null);
        if (!is_array($events) || $events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $parsed = [];
        foreach ($events as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed[] = $this->mapEvent($row);
        }
        if ($parsed === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件若按时间降序返回则反转；升序输出，末条为最新
        $parsed = $this->ensureAscending($parsed);
        $latest = $parsed[count($parsed) - 1];

        return new Tracking(
            carrierCode: 'croatia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $parsed,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $this->pick($latest->raw, ['status', 'event', 'description']),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('croatia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('croatia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('croatia-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = $this->pick($row, ['status', 'event', 'description']);

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? $row['eventDate'] ?? null),
            location: $this->pick($row, ['location', 'office', 'place', 'city']),
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

    /** 支持 'd.m.Y H:i:s'（官网格式）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'd.m.Y H:i:s', 'd.m.Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d.m.Y', '!d/m/Y', '!d-m-Y', '!Y-m-d',
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
