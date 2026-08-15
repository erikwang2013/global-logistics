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
 * Makedonska Pošta（北马其顿邮政）国际件查询（官方公开 JSON API，
 * GET + code 参数，无认证；响应 success/data[] 数组，每行
 * Од | До | Датум | Забелешка）。
 *
 * VERIFIED-REQUIRED: 契约基于官方站点公开 JSON API
 * https://www.posta.com.mk/api/api.php/shipment?code={trackingNo}（官方跟踪页
 * https://www.posta.com.mk/Tracking/index.html 所用端点，第三方 PHP 包
 * kalimeromk/postal-tracking 亦有相同契约），需实网验证（数据键为马其顿语
 * 西里尔字母，日期为 Y-m-d 无时间部分，本适配器按当日零点处理；success=false
 * 或 data 为空数组按无事件处理；状态关键词为 Makedonska Pošta 官网马其顿语
 * 标准描述）。
 * 文档: https://www.posta.com.mk/api/api.php/shipment?code=
 */
final class MacedoniaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.posta.com.mk/api/api.php/shipment';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含马其顿语）。
     * 'во доставка'（派送中）须先于 'испорачана'（已送达）。
     */
    private const STATUS_MAP = [
        'во доставка|во достава|for delivery|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'испорачана|испорачена|доставена|delivered' => TrackStatus::DELIVERED,
        'вратена|вратено|враќање|return' => TrackStatus::RETURNED,
        'неуспешна|одбиена|failed|held|unclaimed|undeliver|exception' => TrackStatus::EXCEPTION,
        'примена|применета|accepted|received|collected|picked up|ready for pickup' => TrackStatus::PENDING,
        'во транзит|in transit|transit|транзит|пристигната|испратена|arrived|departed|sorted|dispatched|forwarded|processed|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('macedonia-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'code' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[MACEDONIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[MACEDONIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[MACEDONIA-POST] 响应解析失败');
        }

        $rows = $result['data'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'macedonia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['Забелешка'] ?? '',
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('macedonia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('macedonia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('macedonia-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['Забелешка'] ?? '');
        if ($description === '') {
            $description = (string) ($row['Од'] ?? '');
        }
        $location = trim((string) ($row['До'] ?? ''));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['Датум'] ?? null),
            location: $location,
            description: $description,
            status: $this->mapStatus($description),
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

    /** 支持 'Y-m-d'（官网格式，无时间部分）、ISO8601、'd/m/Y' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒（仅限 ISO8601 时间戳，避免误伤 d.m.Y 日期）
        if (str_contains($raw, 'T') && preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'd/m/Y H:i:s', 'd/m/Y H:i',
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d/m/Y', '!Y-m-d',
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
