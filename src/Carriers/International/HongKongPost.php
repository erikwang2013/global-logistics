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
 * 香港邮政（HONG-KONG-POST）适配器。
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（EC-Ship Mail Tracking API
 * 为 SOAP 服务，getMTTInfo 里程碑查询的 SOAP 信封与 milestoneList 字段为
 * EC-Ship_API_Specification.pdf 文档推断，凭据需向香港邮政申请 EC-Ship API 账户）。
 * 文档: https://ec-ship.hongkongpost.hk/API-portal/
 */
final class HongKongPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.hongkongpost.hk/API/services/Tracking';

    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const SERVICE_NS = 'http://webservice.integrator.hkpost.com';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $body = $this->buildSoapRequest($trackingNo);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[HONG-KONG-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[HONG-KONG-POST %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[HONG-KONG-POST] 响应解析失败');
        }

        $return = $this->firstByLocalName($parsed, 'getMTTInfoReturn');
        if ($return === null) {
            throw new LogisticsException('[HONG-KONG-POST] 响应解析失败');
        }

        $statusCode = trim((string) $this->child($return, 'status'));
        if ($statusCode !== '' && $statusCode !== '0') {
            throw new LogisticsException(sprintf(
                '[HONG-KONG-POST] %s',
                trim((string) $this->child($return, 'errMessage')) ?: '业务错误',
            ));
        }

        $milestoneList = $this->child($return, 'milestoneList');
        if ($milestoneList === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($this->childrenByLocalName($milestoneList, 'milestone') as $milestone) {
            $events[] = $this->mapEvent($milestone);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'hong-kong-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['milestone_no'] ?? ''),
            raw: ['item_no' => (string) ($this->child($return, 'itemNo') ?? '')],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('HONG-KONG-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('HONG-KONG-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('HONG-KONG-POST subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $milestone): TrackingEvent
    {
        $name = (string) $this->child($milestone, 'milestoneName');
        $description = (string) $this->child($milestone, 'milestoneDescription');
        $eventDate = (string) $this->child($milestone, 'eventDate');
        $eventTime = (string) $this->child($milestone, 'eventTime');

        return new TrackingEvent(
            occurredAt: $this->parseTime($eventDate, $eventTime),
            location: (string) $this->child($milestone, 'eventLocation'),
            description: $description !== '' ? $description : $name,
            status: $this->mapStatus($name, $description),
            raw: [
                'milestone_no' => (string) $this->child($milestone, 'milestoneNo'),
                'milestone_name' => $name,
                'event_date' => $eventDate,
                'event_time' => $eventTime,
                'event_location' => (string) $this->child($milestone, 'eventLocation'),
                'milestone_description' => $description,
            ],
        );
    }

    private function mapStatus(string $name, string $description): TrackStatus
    {
        $text = strtolower($name . ' ' . $description);

        if (str_contains($text, 'delivered')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'out for delivery')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'return')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'failed') || str_contains($text, 'held') || str_contains($text, 'undeliverable')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'transit') || str_contains($text, 'accept') || str_contains($text, 'arriv')) {
            return TrackStatus::IN_TRANSIT;
        }
        if (str_contains($text, 'posted') || str_contains($text, 'collected')) {
            return TrackStatus::PENDING;
        }

        return TrackStatus::UNKNOWN;
    }

    /** eventDate 与 eventTime 分开返回（如 "2026-08-14" + "10:00"） */
    private function parseTime(string $date, string $time): ?\DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }
        $raw = trim($date . ' ' . $time);

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:sP', 'd/m/Y H:i'] as $format) {
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

    private function buildSoapRequest(string $trackingNo): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="' . self::SOAP_NS . '" xmlns:web="' . self::SERVICE_NS . '">'
            . '<soapenv:Body>'
            . '<web:getMTTInfo>'
            . '<web:apiInput>'
            . '<ecshipUsername>' . $this->esc((string) $this->config->get('hong-kong-post.ecship_username')) . '</ecshipUsername>'
            . '<hkpId>' . $this->esc((string) $this->config->get('hong-kong-post.hkp_id')) . '</hkpId>'
            . '<integratorUsername>' . $this->esc((string) $this->config->get('hong-kong-post.integrator_username')) . '</integratorUsername>'
            . '<itemNo>' . $this->esc($trackingNo) . '</itemNo>'
            . '</web:apiInput>'
            . '</web:getMTTInfo>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        return $xml;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    /** 忽略命名空间前缀，按 local-name 取首个匹配子节点 */
    private function child(\SimpleXMLElement $element, string $localName): ?\SimpleXMLElement
    {
        $nodes = $element->xpath('./*[local-name()="' . $localName . '"]');

        return ($nodes === false || $nodes === []) ? null : $nodes[0];
    }

    /** 忽略命名空间前缀，按 local-name 取所有匹配子节点 */
    private function childrenByLocalName(\SimpleXMLElement $element, string $localName): array
    {
        $nodes = $element->xpath('./*[local-name()="' . $localName . '"]');

        return ($nodes === false || $nodes === []) ? [] : $nodes;
    }

    /** 全文档范围按 local-name 取首个匹配节点（用于响应 return 元素） */
    private function firstByLocalName(\SimpleXMLElement $root, string $localName): ?\SimpleXMLElement
    {
        $nodes = $root->xpath('//*[local-name()="' . $localName . '"]');

        return ($nodes === false || $nodes === []) ? null : $nodes[0];
    }
}
