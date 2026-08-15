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
 * India Post（印度邮政）国际件查询（官方公开网页 JSON 接口 GetTrackingDetails，
 * GET，无需认证；openAPI 需 api.indiapost.gov.in 平台密钥，未采用）。
 *
 * VERIFIED-REQUIRED: 契约基于官方网页接口逆向（captn3m0/indiapost-tracker 等
 * 开源项目），需实网验证（响应为事件数组，字段 Date("DD/MM/YYYY")/Time("HH:MM:SS
 * AM")/Location/Status/Remarks，按时间降序返回（最新在前）；无单号时返回空数组
 * 或错误对象，均按无事件处理；Date 为本地时间 IST）。
 * 文档: https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking.Common/Apps/Track/GetTrackingDetails.aspx
 */
final class IndiaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking.Common/Apps/Track/GetTrackingDetails.aspx';

    /**
     * Status/Remarks 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery' 必须先于 'delivered'（"delivery" 包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|out for delivery attempted' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered' => TrackStatus::DELIVERED,
        'returned|return|refused' => TrackStatus::RETURNED,
        'undelivered|undeliverable|unclaimed|held|damaged|missed|not delivered' => TrackStatus::EXCEPTION,
        'booked|ready for delivery|awaiting|received at|accepted' => TrackStatus::PENDING,
        'in transit|transit|dispatched|despatched|bagged|sent|arrived|sorted|processed|on the way' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('india-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'PostalIdx' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[INDIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[INDIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !array_is_list($result)) {
            throw new LogisticsException('[INDIA-POST] 响应解析失败');
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
        // 官方按时间降序返回（最新在前）；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'india-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['Status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('india-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('india-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('india-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $status = (string) ($row['Status'] ?? '');
        $remarks = (string) ($row['Remarks'] ?? '');
        $description = $status !== '' ? $status : $remarks;

        return new TrackingEvent(
            occurredAt: $this->parseTime(trim((string) ($row['Date'] ?? '') . ' ' . (string) ($row['Time'] ?? ''))),
            location: (string) ($row['Location'] ?? ''),
            description: $description,
            status: $this->mapStatus($status . ' ' . $remarks),
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

    /** 支持 'd/m/Y g:i:s A'（官网格式，12 小时制）、'Y-m-d H:i:s'、ISO8601 等，解析失败返回 null */
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

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y g:i:s A', 'd-m-Y g:i:s A', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'M/d/Y g:i:s A', 'n/j/Y g:i:s A', 'd/m/Y g:i A',
            'Y-m-d', 'd-m-Y', 'd/m/Y',
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
