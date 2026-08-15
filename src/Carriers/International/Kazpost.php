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
 * Kazpost（哈萨克斯坦邮政）国际件查询（官网 post.kz 公共跟踪接口，POST JSON + code，
 * 无认证；响应 data.history 为事件数组，按时间升序返回）。
 *
 * VERIFIED-REQUIRED: 官方 API（Tracking Service，需向 postcode@post.kz 申请）非公开，
 * 契约基于官网 post.kz/en/tracking 公共跟踪组件逆向（社区资料表明 POST JSON
 * {"code":"..."}，响应 data.history 事件含 date/place/description 等字段，需实网验证；
 * 单号无效时 history 为空数组或 data 缺失，按无事件处理；状态关键词为官网俄语/哈萨克语
 * 标准描述，未确认字段以通用关键词兜底）。
 * 文档: https://post.kz/en/tracking
 */
final class Kazpost implements CarrierInterface
{
    private const ENDPOINT = 'https://post.kz/track';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，俄语/哈萨克语/英语）。
     * 'в доставке'（派送中）须先于 'вручено'（已交付）等一般关键词。
     */
    private const STATUS_MAP = [
        'out for delivery|в доставке|жеткізілуде' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|вручено|доставлено|жеткізілді' => TrackStatus::DELIVERED,
        'returned|return|возврат|вернул|қайтарылды' => TrackStatus::RETURNED,
        'failed|exception|не вручен|неудач|отказ|невостребован|undeliver|ошибк' => TrackStatus::EXCEPTION,
        'ready for pickup|ожидает выдачи|ожидание|готов к выдаче|жеткізуге дайын|pending' => TrackStatus::PENDING,
        'in transit|in_transit|транзит|прибыл|отправлен|принят|обработк|передан|находится в пути|в пути|сортировк|импорт|экспорт|посылка|transit|arrived|departed|accepted|processed' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('kazpost.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode(['code' => $trackingNo], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[KAZPOST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[KAZPOST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[KAZPOST] 响应解析失败');
        }

        $data = $result['data'] ?? null;
        $history = is_array($data) ? ($data['history'] ?? $data['events'] ?? $data['moves'] ?? null) : ($result['history'] ?? $result['events'] ?? null);
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
            carrierCode: 'kazpost',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $this->pick($latest->raw, ['status', 'statusName', 'description']),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('kazpost createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('kazpost createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('kazpost subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = $this->pick($row, ['description', 'statusName', 'status']);

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? $row['eventDate'] ?? $row['dateTime'] ?? null),
            location: $this->pick($row, ['place', 'location', 'office', 'address']),
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        // 俄语/哈萨克语关键词需 mb_strtolower（strtolower 仅处理 ASCII）
        $text = mb_strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s'、'd.m.Y H:i:s' 等，解析失败返回 null */
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
            'Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd-m-Y H:i:s',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d.m.Y', '!d-m-Y', '!Y-m-d',
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
