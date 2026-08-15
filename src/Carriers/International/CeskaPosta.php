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
 * Česká pošta（捷克邮政）国际件查询（公开 ParcelHistory JSON 接口，GET，无需认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开端点模式，需实网验证（响应为事件 JSON 数组，字段
 * dateTime/code/text/postCode/postName/country 依据社区 PHP 客户端 contributte/czech-post
 * 文档推断；查无单号时返回空数组）。文档: https://www.ceskaposta.cz/english/sledovani-zasilek
 */
final class CeskaPosta implements CarrierInterface
{
    private const ENDPOINT = 'https://b2c.cpost.cz/services/ParcelHistory/getDataAsJson?idParcel={trackingNo}';

    /**
     * 事件文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含捷克语）。
     * 'doručování'（派送中）必须先于 'doručeno'（已投递）相关词。
     */
    private const STATUS_MAP = [
        'doručování|doručována|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'doručeno|doručení|delivered|převzato' => TrackStatus::DELIVERED,
        'vrácen|vrácení|returned|return' => TrackStatus::RETURNED,
        'nedoručeno|exception|failed|chyba' => TrackStatus::EXCEPTION,
        'podání|podáno|received|přijato|uloženo|ready|k vyzvednutí' => TrackStatus::PENDING,
        'přepravě|přeprava|transit|expedována|in transit' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('ceska-posta.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', rawurlencode($trackingNo), $endpoint), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CESKA-POSTA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CESKA-POSTA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CESKA-POSTA] 响应解析失败');
        }
        if ($result === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($result as $row) {
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
            carrierCode: 'ceska-posta',
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
        throw new LogisticsException('ceska-posta createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ceska-posta createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ceska-posta subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $text = (string) ($row['text'] ?? '');
        $code = (string) ($row['code'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dateTime'] ?? null),
            location: trim((string) ($row['postName'] ?? '') . ' ' . (string) ($row['postCode'] ?? '')),
            description: $text,
            status: $this->mapStatus($text . ' ' . $code),
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

    /** 支持 ISO8601 带时区（含毫秒/微秒）、'Y-m-d H:i:s' 等，解析失败返回 null */
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

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
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
