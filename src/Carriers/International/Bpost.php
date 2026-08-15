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
 * bpost（比利时邮政）国际件查询（Shipping Manager API，Basic 认证，XML 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（/tracks 资源路径与 trackingInfo
 * 响应字段名按 bpack 集成手册与 Shipping Manager API（application/vnd.bpost.shm-*+XML）
 * 推断；AccountID:Passphrase 为官方 Basic 凭证格式）。
 * 文档: https://bpost.freshdesk.com/support/solutions/articles/4000037653
 */
final class Bpost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.bpost.be/services/shm/{accountId}/tracks/{trackingNo}';

    /**
     * event description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含荷兰语/法语）。
     */
    private const STATUS_MAP = [
        'delivered|afgeleverd|livré|livree|bezorgd' => TrackStatus::DELIVERED,
        'out for delivery|distribution|reparto|aangeboden' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|retour|terug|retourneren' => TrackStatus::RETURNED,
        'failed|attempt|exception|verhinderd|incident' => TrackStatus::EXCEPTION,
        'collected|accepted|received|label created|aangemaakt|annonce|ontvangen' => TrackStatus::PENDING,
        'in transit|onderweg|en route|arrived|departed|aangekomen|vertrokken|sorted|processed' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = str_replace(
            ['{accountId}', '{trackingNo}'],
            [
                rawurlencode((string) $this->config->get('bpost.account_id')),
                rawurlencode($trackingNo),
            ],
            (string) $this->config->get('bpost.endpoint', self::ENDPOINT),
        );

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint, [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config->get('bpost.account_id') . ':' . (string) $this->config->get('bpost.password'),
            ),
            'Accept' => 'application/vnd.bpost.shm-trackResponse-v3+XML',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[BPOST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[BPOST %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[BPOST] 响应解析失败');
        }

        $eventNodes = $parsed->xpath('//*[local-name()="event"]');
        if ($eventNodes === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($eventNodes as $event) {
            $events[] = $this->mapEvent($event);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'bpost',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['code'] ?? '',
            raw: [
                'barcode' => $this->xmlChild($parsed, 'barcode'),
                'events' => $this->simpleXmlToArray($eventNodes),
            ],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('bpost createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('bpost createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('bpost subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $event): TrackingEvent
    {
        $description = $this->xmlChild($event, 'description');

        return new TrackingEvent(
            occurredAt: $this->parseTime($this->xmlChild($event, 'date')),
            location: $this->xmlChild($event, 'location'),
            description: $description,
            status: $this->mapStatus($description),
            raw: [
                'code' => $this->xmlChild($event, 'code'),
                'description' => $description,
                'date' => $this->xmlChild($event, 'date'),
                'location' => $this->xmlChild($event, 'location'),
            ],
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

    /** 支持 ISO8601 带时区（含毫秒/时区偏移）、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /** 按 local-name 取子节点文本，规避默认命名空间差异 */
    private function xmlChild(\SimpleXMLElement $node, string $name): string
    {
        $found = $node->xpath('*[local-name()="' . $name . '"]');

        return $found === [] ? '' : trim((string) $found[0]);
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

    /** @param \SimpleXMLElement[] $events @return array<int, array<string, string>> */
    private function simpleXmlToArray(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'code' => $this->xmlChild($event, 'code'),
                'description' => $this->xmlChild($event, 'description'),
                'date' => $this->xmlChild($event, 'date'),
                'location' => $this->xmlChild($event, 'location'),
            ];
        }

        return $rows;
    }
}
