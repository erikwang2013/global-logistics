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
 * TNT（TNT Express，国际快递）适配器。
 *
 * Necta JSON Tracking V1（官方 ExpressConnect/Necta 登录 ID+密码认证，非 OAuth2）。
 * VERIFIED-REQUIRED: 契约基于官方技术指南（endpoint、login/searchCriteria/detail
 * 请求结构、statusData/events 响应结构、9 位单号已确认），事件数组字段形态与
 * 摘要状态码映射需开通 Necta 登录账号后实网验证。
 * 文档: https://express.tnt.com/trackingapidocumentation/userguide/Necta%20Json%20User%20Guide%20V1.03.pdf
 */
final class Tnt implements CarrierInterface
{
    private const ENDPOINT = 'https://express.tnt.com/expressconnect/itrack';

    /**
     * statusDescription 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        'delivered' => TrackStatus::DELIVERED,
        'out for delivery|on vehicle' => TrackStatus::OUT_FOR_DELIVERY,
        'returned' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|undeliverable' => TrackStatus::EXCEPTION,
        'collected|received|booked|manifested' => TrackStatus::PENDING,
        'in transit|transit|arrived|departed|sorted|processed|at depot' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('tnt.endpoint', self::ENDPOINT);
        $body = json_encode([
            'login' => [
                'companyId' => $this->config->get('tnt.company_id'),
                'password' => $this->config->get('tnt.password'),
            ],
            'searchCriteria' => [
                'consignmentNumber' => [$trackingNo],
            ],
            'detail' => [
                'levelOfDetail' => 'full',
            ],
        ]);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[TNT %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[TNT %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[TNT] 响应解析失败');
        }

        $consignment = $result['consignments'][0] ?? null;
        if (!is_array($consignment)) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // CNF = Consignment Not Found
        if (strtoupper((string) ($consignment['statusData']['statusCode'] ?? '')) === 'CNF') {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $consignment['events'] ?? [];
        if (!is_array($rawEvents) || $rawEvents === []) {
            // summary 请求无 events 时回退到摘要状态节点
            $statusData = $consignment['statusData'] ?? null;
            $rawEvents = is_array($statusData) ? [$statusData] : [];
        }
        if ($rawEvents === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($rawEvents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'tnt',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['statusCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('tnt createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('tnt createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('tnt subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['statusDescription'] ?? '');
        $depot = $row['depot'] ?? null;

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row['localEventDate'] ?? '') . (string) ($row['localEventTime'] ?? '')),
            location: is_array($depot) ? (string) ($depot['depotName'] ?? '') : '',
            description: $description,
            status: $this->mapStatus($description, (string) ($row['statusCode'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description, string $statusCode): TrackStatus
    {
        $text = strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
                }
            }
        }
        if (strtoupper($statusCode) === 'DEL') {
            return TrackStatus::DELIVERED;
        }

        return TrackStatus::UNKNOWN;
    }

    /** localEventDate(YYYYMMDD)+localEventTime(HHMM) 组合解析；失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['YmdHi', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
