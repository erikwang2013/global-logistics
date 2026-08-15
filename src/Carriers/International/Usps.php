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
 * USPS 国际件查询（TrackV2 XML API，无 OAuth，纯 GET + XML 响应）。
 *
 * 文档: https://www.usps.com/business/web-tools-apis/track-and-confirm-api.htm
 */
final class Usps implements CarrierInterface
{
    private const ENDPOINT = 'https://secure.shippingapis.com/ShippingAPI.dll';

    /**
     * TrackDetail 描述关键词 => 统一状态。
     * 'DELIVERED' 必须保持最后：'ACCEPT' 会命中 "ACCEPTANCE"（PENDING），
     * 其余关键词不得截断更具体的描述。
     */
    private const STATUS_MAP = [
        'ACCEPT' => TrackStatus::PENDING,
        'IN TRANSIT' => TrackStatus::IN_TRANSIT,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'DELIVERED' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<TrackFieldRequest USERID="' . htmlspecialchars((string) $this->config->get('usps.user_id'), ENT_XML1 | ENT_QUOTES) . '">'
            . '<TrackID ID="' . htmlspecialchars($trackingNo, ENT_XML1 | ENT_QUOTES) . '"/>'
            . '</TrackFieldRequest>';

        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'API' => 'TrackV2',
            'XML' => $xml,
        ]));

        $response = $this->http->sendRequest($request);

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[USPS] 响应解析失败');
        }

        $this->throwForApiError($parsed);

        $trackInfo = $parsed->TrackInfo ?? null;
        if ($trackInfo === null || count($trackInfo->TrackDetail ?? []) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($trackInfo->TrackDetail as $detail) {
            $event = $this->mapEvent($detail);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'usps',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->description,
            raw: ['track_summary' => (string) ($trackInfo->TrackSummary ?? '')],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('USPS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('USPS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('USPS subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $detail): ?TrackingEvent
    {
        $text = trim((string) $detail);
        if ($text === '') {
            return null;
        }

        // 形如 "August 14, 2026, 10:00 am, Picked up, MEMPHIS"
        if (!preg_match('/^([A-Za-z]+ \d{1,2}, \d{4}, \d{1,2}:\d{2} [APap]m), (.*)$/', $text, $m)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('F j, Y, g:i a', $m[1]);
        $occurredAt = $dt === false ? null : $dt;

        // 描述与地点共用逗号分隔（如 "Delivered, BERLIN"），在最后一个逗号处拆分
        $rest = $m[2];
        $pos = strrpos($rest, ',');
        if ($pos === false) {
            $description = $rest;
            $location = '';
        } else {
            $description = trim(substr($rest, 0, $pos));
            $location = trim(substr($rest, $pos + 1));
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: $location,
            description: $description,
            status: $this->mapStatus($description),
            raw: ['text' => $text],
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $upper = strtoupper($description);
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($upper, $keyword)) {
                return $status;
            }
        }

        return TrackStatus::UNKNOWN;
    }

    private function throwForApiError(\SimpleXMLElement $parsed): void
    {
        $error = $parsed->Error ?? null;
        if ($error === null) {
            return;
        }

        $code = (string) $error->Number;
        $message = (string) $error->Description;
        if (in_array($code, ['80040B1A', '80040B1C', '80040B20'], true)) {
            throw new AuthException(sprintf('[USPS %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[USPS %s] %s', $code, $message));
    }
}
