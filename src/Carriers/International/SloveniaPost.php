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
 * Pošta Slovenije（斯洛文尼亚邮政）国际件查询（官方 REST 服务，POST + JSON，
 * 旧 API 免认证，新 Azure API 需 OAuth Bearer Token，均支持一次查询多个条码）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 REST 服务说明（espremnica.posta.si PDF 文档
 * ProviderResponse/ProcessStatus 结构）与社区集成（ha-posta.si）逆向，需实网验证
 * （2025-10-01 起旧 API 下线，新 API 需 Bearer Token 且字段名变更；getShipmentData
 * 路径与 Fields 枚举以官方 RC 脚本为准；事件按时间降序返回，EventDateTime 为 ISO8601）。
 * 文档: https://en.posta.si/home/postal-services-/faq-switching-to-the-new-tracking-api
 */
final class SloveniaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://en.posta.si/restservices/Posta.Si.RESTService/Data/getShipmentData';

    /**
     * EventText/EventCode/StatusText 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'na naslovu'（派送中）须在 'dostavljeno' 等一般关键词之前。
     */
    private const STATUS_MAP = [
        'out for delivery|na naslovu|izročitev' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|dostavljeno|izročeno|vročeno' => TrackStatus::DELIVERED,
        'returned|return|vračilo|vrnjeno|nevročeno' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|poškodovano|zavrnjeno|nedostavljivo|ni uspelo|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available|prevzem|pripravljeno|na pošti|čaka|picked up|collected' => TrackStatus::PENDING,
        'in transit|transit|v tranzitu|odpravljeno|sprejeto|oddano|razvrščeno|poslano|na poti|prejeto|received|arrived|departed|sorted|dispatched|accepted|shipped|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('slovenia-post.endpoint', self::ENDPOINT);
        $token = (string) $this->config->get('slovenia-post.key');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, $headers, json_encode([
            'CountryCode' => 'SI',
            'TrackingNumbers' => [$trackingNo],
            'Fields' => [
                'EventDateTime', 'EventCode', 'EventText', 'StatusText',
                'City', 'Country', 'IsDelivered', 'PackageStatus', 'DeliveryDate',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SLOVENIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SLOVENIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SLOVENIA-POST] 响应解析失败');
        }

        $data = $result['Data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $parcel = $data[0]['Parcels'][0] ?? null;
        if (!is_array($parcel)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($parcel['Events'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 官方事件按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'slovenia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($parcel['PackageStatus'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('slovenia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('slovenia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('slovenia-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $eventText = (string) ($row['EventText'] ?? '');
        $statusText = (string) ($row['StatusText'] ?? '');
        $description = $eventText !== '' ? $eventText : $statusText;

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['EventDateTime'] ?? null),
            location: (string) ($row['City'] ?? $row['Country'] ?? ''),
            description: $description,
            status: $this->mapStatus($description . ' ' . (string) ($row['EventCode'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        // 多字节文本（斯洛文尼亚语等）需 mb_strtolower，否则 UTF-8 大写字符不转小写
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

    /** 支持 ISO8601（含时区）、'd-m-Y H:i' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒（如 2023-12-18T11:20:06.123456+01:00）截断为 3 位毫秒
        if (preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!Y-m-d', '!d-m-Y', '!d.m.Y',
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
