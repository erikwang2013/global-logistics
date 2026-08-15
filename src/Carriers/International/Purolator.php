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
 * Purolator（加拿大快递）国际件查询（E-Ship Web Services，SOAP TrackByPin，Basic 认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（SOAP 命名空间、RequestContext
 * 头、TrackByPin 请求/响应结构与 ScanType 取值均按 Purolator E-Ship® Web Services
 * 集成指南推断；生产 key/password 即 Basic 认证凭证）。
 * 文档: https://www.purolator.com/en/services/technology-solutions/e-ship-web-services
 */
final class Purolator implements CarrierInterface
{
    private const ENDPOINT = 'https://webservices.purolator.com/PWS/V1/Tracking/TrackingService.asmx';

    private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const NS_PWS = 'http://purolator.com/pws/datatypes/v1';

    /**
     * ScanType 代码 => 统一状态（Purolator 公开文档定义的扫描类型代码）。
     */
    private const CODE_MAP = [
        'D' => TrackStatus::DELIVERED,
        'OFD' => TrackStatus::OUT_FOR_DELIVERY,
        'P' => TrackStatus::PENDING,
        'T' => TrackStatus::IN_TRANSIT,
        'DEL_ATT' => TrackStatus::EXCEPTION,
        'X' => TrackStatus::EXCEPTION,
        'R' => TrackStatus::RETURNED,
    ];

    /**
     * Description 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        'delivered' => TrackStatus::DELIVERED,
        'out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'returned|return' => TrackStatus::RETURNED,
        'delivery attempted|delivery failed|exception|held' => TrackStatus::EXCEPTION,
        'picked up|received' => TrackStatus::PENDING,
        'in transit|arrived|departed|sorted|processed|scanned' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', (string) $this->config->get('purolator.endpoint', self::ENDPOINT), [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config->get('purolator.production_key') . ':' . (string) $this->config->get('purolator.password'),
            ),
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://purolator.com/pws/service/v1/TrackByPin"',
        ], $this->buildSoapRequest($trackingNo));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[PUROLATOR %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[PUROLATOR %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[PUROLATOR] 响应解析失败');
        }

        $errorNodes = $parsed->xpath('//*[local-name()="Errors"]/*[local-name()="Error"]');
        $scanNodes = $parsed->xpath('//*[local-name()="Scans"]/*[local-name()="Scan"]');
        $pinNodes = $parsed->xpath('//*[local-name()="TrackingInformation"]/*[local-name()="PIN"]/*[local-name()="Value"]');
        if ($errorNodes !== [] || $scanNodes === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($scanNodes as $scan) {
            $events[] = $this->mapEvent($scan);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'purolator',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['scan_type'] ?? '',
            raw: [
                'pin' => $pinNodes === [] ? '' : trim((string) $pinNodes[0]),
                'scans' => $this->simpleXmlToArray($scanNodes),
            ],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('purolator createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('purolator createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('purolator subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $scan): TrackingEvent
    {
        $scanType = $this->xmlChild($scan, 'ScanType');
        $description = $this->xmlChild($scan, 'Description');

        return new TrackingEvent(
            occurredAt: $this->parseScanTime($scan),
            location: $this->xmlChild($scan, 'Location'),
            description: $description,
            status: $this->mapStatus($scanType, $description),
            raw: [
                'scan_type' => $scanType,
                'description' => $description,
                'date' => $this->xmlChild($scan, 'Date'),
                'time' => $this->xmlChild($scan, 'Time'),
                'location' => $this->xmlChild($scan, 'Location'),
            ],
        );
    }

    private function mapStatus(string $scanType, string $description): TrackStatus
    {
        if (isset(self::CODE_MAP[$scanType])) {
            return self::CODE_MAP[$scanType];
        }

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

    /** Date 可能为完整时间戳（"2026-08-14T17:45:00"）或仅日期，Time 单独返回 */
    private function parseScanTime(\SimpleXMLElement $scan): ?\DateTimeImmutable
    {
        $date = $this->xmlChild($scan, 'Date');
        $time = $this->xmlChild($scan, 'Time');
        if ($date === '') {
            return null;
        }
        $raw = str_contains($date, 'T') || $time === '' ? $date : $date . ' ' . $time;

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /** 按 local-name 取子节点文本，规避 SOAP/业务命名空间差异 */
    private function xmlChild(\SimpleXMLElement $node, string $name): string
    {
        $found = $node->xpath('*[local-name()="' . $name . '"]');

        return $found === [] ? '' : trim((string) $found[0]);
    }

    private function buildSoapRequest(string $trackingNo): string
    {
        $version = '1.2';
        $language = (string) $this->config->get('purolator.language', 'en');
        $groupId = (string) $this->config->get('purolator.group_id', '');

        return '<?xml version="1.0" encoding="utf-8"?>' .
            '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:pws="' . self::NS_PWS . '">' .
            '<soap:Header>' .
            '<pws:RequestContext>' .
            '<pws:Version>' . $version . '</pws:Version>' .
            '<pws:Language>' . $language . '</pws:Language>' .
            '<pws:GroupID>' . htmlspecialchars($groupId, ENT_XML1) . '</pws:GroupID>' .
            '<pws:RequestReference>tracking</pws:RequestReference>' .
            '</pws:RequestContext>' .
            '</soap:Header>' .
            '<soap:Body>' .
            '<pws:TrackByPinRequest>' .
            '<pws:PIN><pws:Value>' . htmlspecialchars($trackingNo, ENT_XML1) . '</pws:Value></pws:PIN>' .
            '</pws:TrackByPinRequest>' .
            '</soap:Body>' .
            '</soap:Envelope>';
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

    /** @param \SimpleXMLElement[] $scans @return array<int, array<string, string>> */
    private function simpleXmlToArray(array $scans): array
    {
        $rows = [];
        foreach ($scans as $scan) {
            $rows[] = [
                'scan_type' => $this->xmlChild($scan, 'ScanType'),
                'description' => $this->xmlChild($scan, 'Description'),
                'date' => $this->xmlChild($scan, 'Date'),
                'time' => $this->xmlChild($scan, 'Time'),
                'location' => $this->xmlChild($scan, 'Location'),
            ];
        }

        return $rows;
    }
}
