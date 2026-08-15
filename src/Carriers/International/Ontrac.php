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
 * ONTRAQ（美国西部尾程，现并入 Lasership）国际件查询（Shipping API，账号+密码，XML 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（API_Shipping.asp 的表单参数
 * RequestType=3、strAccountNo/strPassPhrase 与跟踪响应 XML 字段名均按 OnTrac 官方
 * Shipping API 说明页推断，单号校验位算法与 UPS 相同）。
 * 文档: https://west.ontrac.com/onlineShipAPI.asp
 */
final class Ontrac implements CarrierInterface
{
    private const ENDPOINT = 'https://www.ontrac.com/API_Shipping.asp';

    /**
     * Event Description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        'delivered' => TrackStatus::DELIVERED,
        'out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|return' => TrackStatus::RETURNED,
        'delivery failed|delivery exception|exception|held|unable' => TrackStatus::EXCEPTION,
        'picked up|label created|billing information received|shipment information' => TrackStatus::PENDING,
        'in transit|arrived|departed|sorted|received at|scanned|processed' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', (string) $this->config->get('ontrac.endpoint', self::ENDPOINT), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/xml',
        ], http_build_query([
            'strAccountNo' => (string) $this->config->get('ontrac.account_no'),
            'strPassPhrase' => (string) $this->config->get('ontrac.password'),
            'RequestType' => '3',
            'TrackingNo' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ONTRAC %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ONTRAC %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[ONTRAC] 响应解析失败');
        }

        $track = $parsed->Track ?? null;
        $rawEvents = $track->Events->Event ?? null;
        if ($track === null || $rawEvents === null || count($rawEvents) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($rawEvents as $event) {
            $events[] = $this->mapEvent($event);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ontrac',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($track->StatusMessage ?? ''),
            raw: [
                'tracking_number' => (string) ($track->TrackingNumber ?? ''),
                'status_message' => (string) ($track->StatusMessage ?? ''),
                'events' => $this->simpleXmlToArray($rawEvents),
            ],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('ontrac createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ontrac createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ontrac subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $event): TrackingEvent
    {
        $description = (string) ($event->Description ?? '');
        $city = (string) ($event->City ?? '');
        $state = (string) ($event->State ?? '');
        $zip = (string) ($event->Zip ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($event->DateTime ?? '')),
            location: trim(rtrim($city . ', ' . $state, ', ') . ' ' . $zip),
            description: $description,
            status: $this->mapStatus($description),
            raw: [
                'datetime' => (string) ($event->DateTime ?? ''),
                'description' => $description,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'code' => (string) ($event->Code ?? ''),
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

    /** 支持 ISO8601 带时区、'Y-m-d H:i:s'、'm/d/Y H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'm/d/Y H:i:s', 'm/d/Y H:i', 'Y-m-d'] as $format) {
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

    /** @return array<int, array<string, string>> */
    private function simpleXmlToArray(\SimpleXMLElement $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'datetime' => (string) ($event->DateTime ?? ''),
                'description' => (string) ($event->Description ?? ''),
                'city' => (string) ($event->City ?? ''),
                'state' => (string) ($event->State ?? ''),
                'zip' => (string) ($event->Zip ?? ''),
                'code' => (string) ($event->Code ?? ''),
            ];
        }

        return $rows;
    }
}
