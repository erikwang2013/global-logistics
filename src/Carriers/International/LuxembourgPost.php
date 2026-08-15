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
 * POST Luxembourg（卢森堡邮政）国际件查询（官方 Track & Trace 站点 API，
 * POST + JSON 请求体（跟踪号数组），匿名访问；配置 luxembourg-post.key 可选，
 * 配置后以 X-API-Key 请求头发送，否则不发认证头）。
 *
 * VERIFIED-REQUIRED: 契约基于公开逆向资料（oRRs.de Deliveries 问答，
 * 官网 trackandtrace 页面调用 https://api.post.lu/services/trackandtrace-api/items，
 * JSON POST 载荷为跟踪号列表），需实网验证（请求体形状与响应字段名
 * trackingNumber/events[].eventTime/eventDescription/eventLocation/statusCode
 * 以官网实际输出为准；响应为对象数组，按 trackingNumber 匹配；单号无效时
 * 响应数组为空或缺少匹配项，按无事件处理；eventTime 为 ISO8601 带时区，
 * statusCode 如 FINAL_DELIVERED 为官方事件码）。
 * 文档: https://www.post.lu/en/particuliers/colis-courrier/track-and-trace
 */
final class LuxembourgPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.post.lu/services/trackandtrace-api/items';

    /**
     * eventDescription/statusCode 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，
     * 含法语/德语常用表述）。
     * 'out for delivery' 必须先于 'delivered'。
     */
    private const STATUS_MAP = [
        'out for delivery|tournée de livraison|en livraison|zustellung' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|livré|zugestellt|distributed|remis au destinataire' => TrackStatus::DELIVERED,
        'returned|return|retour|rücksendung' => TrackStatus::RETURNED,
        'failed|exception|held|unclaimed|refused|undeliver|non réclamé|nicht abgeholt' => TrackStatus::EXCEPTION,
        'ready for pickup|available for pickup|collected|picked up|waiting|abholbereit|disponible au retrait' => TrackStatus::PENDING,
        'in transit|in_transit|transit|received|arrived|departed|sorted|dispatched|accepted|handed|shipped|created|registered|booking|sent|en cours|in transport' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('luxembourg-post.endpoint', self::ENDPOINT);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $key = (string) $this->config->get('luxembourg-post.key', '');
        if ($key !== '') {
            $headers['X-API-Key'] = $key;
        }
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, $headers, json_encode([$trackingNo], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[LUXEMBOURG-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[LUXEMBOURG-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[LUXEMBOURG-POST] 响应解析失败');
        }

        $item = null;
        foreach ($result as $entry) {
            if (is_array($entry) && strcasecmp((string) ($entry['trackingNumber'] ?? ''), $trackingNo) === 0) {
                $item = $entry;
                break;
            }
        }
        if ($item === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($item['events'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'luxembourg-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($item['statusCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('luxembourg-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('luxembourg-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('luxembourg-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['eventDescription'] ?? '');
        if ($description === '') {
            $description = (string) ($row['statusCode'] ?? '');
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['eventTime'] ?? null),
            location: (string) ($row['eventLocation'] ?? ''),
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['statusCode'] ?? '')),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'd/m/Y'、'Y-m-d H:i:s' 等，解析失败返回 null */
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
