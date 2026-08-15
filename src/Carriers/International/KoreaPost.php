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
 * 韩国邮政（KOREA-POST）适配器。
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（공공데이터포털
 * 우정사업본부 통합 종적 조회 서비스，serviceKey 为 데이터포털 등록코드，
 * getLongitudinalCombinedList 响应嵌套结构为文档字段表推断）。
 * 文档: https://www.data.go.kr/data/15035122/openapi.do
 */
final class KoreaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.epost.go.kr/trace/retrieveLongitudinalCombinedService/retrieveLongitudinalCombinedService/getLongitudinalCombinedList';

    /** 处理현황/배달상태 关键词 => 统一状态（'배달완료' 必须先于 '배달' 判定） */
    private const STATUS_MAP = [
        '배달완료' => TrackStatus::DELIVERED,
        '배달준비' => TrackStatus::OUT_FOR_DELIVERY,
        '반송' => TrackStatus::RETURNED,
        '집하' => TrackStatus::PENDING,
        '접수' => TrackStatus::PENDING,
        '배달' => TrackStatus::IN_TRANSIT,
        '운송' => TrackStatus::IN_TRANSIT,
        '도착' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'serviceKey' => (string) $this->config->get('korea-post.service_key'),
            'rgist' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[KOREA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[KOREA-POST %s] 接口错误', $status));
        }

        $parsed = @simplexml_load_string((string) $response->getBody(), options: LIBXML_NONET);
        if ($parsed === false) {
            throw new LogisticsException('[KOREA-POST] 响应解析失败');
        }

        $this->throwForApiError($parsed);

        $item = $this->firstItem($parsed);
        if ($item === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rows = $item->list?->item ?? [];
        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'korea-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($item->dlvySttus ?? ''),
            raw: ['dlvy_sttus' => (string) ($item->dlvySttus ?? '')],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('KOREA-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('KOREA-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('KOREA-POST subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $row): TrackingEvent
    {
        $processStatus = (string) ($row->processSttus ?? '');
        $detail = (string) ($row->detailDc ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row->processDe ?? '')),
            location: (string) ($row->nowLc ?? ''),
            description: $detail !== '' ? $detail : $processStatus,
            status: $this->mapStatus($processStatus),
            raw: [
                'process_de' => (string) ($row->processDe ?? ''),
                'process_sttus' => $processStatus,
                'now_lc' => (string) ($row->nowLc ?? ''),
                'detail_dc' => $detail,
            ],
        );
    }

    private function mapStatus(string $processStatus): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($processStatus, $keyword)) {
                return $status;
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /**
     * processDe 形如 "2026.08.14 10:00"（yyyy.mm.dd hh24:mi）。
     */
    private function parseTime(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        foreach (['Y.m.d H:i:s', 'Y.m.d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP'] as $format) {
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

    /** @return \SimpleXMLElement|null 首个 item（msgBody/items/item） */
    private function firstItem(\SimpleXMLElement $parsed): ?\SimpleXMLElement
    {
        $items = $parsed->msgBody->items->item ?? null;
        if ($items === null || count($items) === 0) {
            return null;
        }

        return $items[0];
    }

    private function throwForApiError(\SimpleXMLElement $parsed): void
    {
        $reason = trim((string) ($parsed->comMsgHeader->returnReasonCode ?? ''));
        $message = trim((string) ($parsed->comMsgHeader->errMsg ?? ''));
        if ($reason === '' || $reason === '00') {
            return;
        }
        if ($message === '') {
            $message = sprintf('服务错误（returnReasonCode=%s）', $reason);
        }

        throw new LogisticsException(sprintf('[KOREA-POST] %s', $message));
    }
}
