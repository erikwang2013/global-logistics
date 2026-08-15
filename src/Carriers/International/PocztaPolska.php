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
 * Poczta Polska（波兰邮政）国际件查询（官方 USS REST API，POST checkmailex + api_key）。
 *
 * VERIFIED-REQUIRED: 契约基于官方 REST API 文档，需实网验证（api_key 需在
 * poczta-polska.pl 业务门户申请（公开页面内嵌密钥仅用于官网）；官方文档同时给出
 * GET 与 POST 两种调用形态，本适配器采用 POST；events[].name 按 language 参数
 * 返回波兰语或英语；mailStatus=-1 或缺少 mailInfo 表示无此单号；
 * P_D/P_DOR 等事件码为官方定义）。
 * 文档: https://www.poczta-polska.pl/en/dla-biznesu/wsparcie/integracje/systemy-sledzenia/system-sledzenia-rest-api/
 */
final class PocztaPolska implements CarrierInterface
{
    private const ENDPOINT = 'https://uss.poczta-polska.pl/uss/v2.0/tracking/checkmailex';

    /**
     * 事件 name/state.name 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'w doręczeniu'（派送中）必须先于 'doręczon'（已交付），'doręczon' 为两者的包含关系子串。
     */
    private const STATUS_MAP = [
        'out for delivery|w doręczeniu|prepared for delivery|przygotowano do doręczenia' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|doręczon' => TrackStatus::DELIVERED,
        'returned|return|zwrot' => TrackStatus::RETURNED,
        'failed|missed|exception|held|unclaimed|undeliver|nie podjęt' => TrackStatus::EXCEPTION,
        'ready for pickup|ready to pick up|picked up|collected|do odbioru|awiz' => TrackStatus::PENDING,
        'in transit|transit|received|arrived|departed|sorted|dispatched|accepted|posted|nadan|w transporcie|przygotowan|in transport|sent' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('poczta-polska.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'api_key' => (string) $this->config->get('poczta-polska.api_key', ''),
        ], json_encode([
            'language' => (string) $this->config->get('poczta-polska.language', 'PL'),
            'number' => $trackingNo,
            'addPostOfficeInfo' => false,
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POCZTA-POLSKA %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POCZTA-POLSKA %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POCZTA-POLSKA] 响应解析失败');
        }
        // mailStatus = -1 或缺失 mailInfo 表示查无此单
        $mailInfo = $result['mailInfo'] ?? null;
        if (!is_array($mailInfo) || (int) ($result['mailStatus'] ?? -1) === -1) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $mailInfo['events'] ?? [];
        if (!is_array($rawEvents) || $rawEvents === []) {
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
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'poczta-polska',
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
        throw new LogisticsException('poczta-polska createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('poczta-polska createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('poczta-polska subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $code = (string) ($row['code'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $state = $row['state'] ?? null;
        $stateName = '';
        if (is_array($state)) {
            $stateName = (string) ($state['name'] ?? '');
        }
        $postOffice = $row['postOffice'] ?? null;
        $officeName = '';
        if (is_array($postOffice)) {
            $officeName = (string) ($postOffice['name'] ?? '');
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['time'] ?? null),
            location: $officeName,
            description: $name !== '' ? $name : $code,
            status: $this->mapStatus($code . ' ' . $name . ' ' . $stateName),
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

    /** 支持 'Y-m-d\TH:i:s'（USS 格式）、ISO8601 带时区、'Y-m-d H:i:s' 等，解析失败返回 null */
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

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
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
