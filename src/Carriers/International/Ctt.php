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
 * CTT（葡萄牙邮政）国际件查询（官方网站公共 JSON API，POST + CSRF Token/Cookie，
 * 需先从 ctt.pt 会话中取得 api_version/csrf_token/cookie 后配置）。
 *
 * VERIFIED-REQUIRED: 契约基于 ctt.pt 官网网络请求逆向（cttpie 开源项目），需实网
 * 验证（apiVersion 为页面脚本中的 22 位 base64 token，需随会话刷新；X-CSRFToken 与
 * Cookie 来自浏览器会话；事件列表 List 顺序与 DateTime 格式（推断为
 * "DD-MM-YYYY HH:MM"）未确认，本适配器按容错解析；Found=false 表示无此单号）。
 * 文档: https://github.com/hivesolutions/cttpie (neo handler)
 */
final class Ctt implements CarrierInterface
{
    private const ENDPOINT = 'https://appserver.ctt.pt/CustomerArea/screenservices/CustomerArea/CustomerArea/PublicArea_Detail/DataActionGetObjectEventsByInputObjectCode';

    /**
     * State/Event 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'entregue'（已交付）须在 'dor' 等一般关键词之前；'saída para entrega' 须在 'entrega' 之前。
     */
    private const STATUS_MAP = [
        'out for delivery|saída para entrega|em distribuição|distribuição' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|entregue|entregue ao destinatário' => TrackStatus::DELIVERED,
        'returned|return|devolvid|devolução' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|avariad|recusad|não reclam|undeliver' => TrackStatus::EXCEPTION,
        'ready for pickup|available|aguarda levantamento|em armazém|picked up' => TrackStatus::PENDING,
        'in transit|transit|received|arrived|departed|sorted|dispatched|accepted|triagem|recebida|em trânsito|em transito|expediç|nada' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('ctt.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CSRFToken' => (string) $this->config->get('ctt.csrf_token', ''),
            'Cookie' => (string) $this->config->get('ctt.cookie', ''),
        ], json_encode([
            'versionInfo' => [
                'apiVersion' => (string) $this->config->get('ctt.api_version', ''),
            ],
            'viewName' => 'CustomerArea.PublicArea_Detail',
            'screenData' => [
                'variables' => [
                    'ObjectsLength' => -1,
                    'ObjectCodeInput' => $trackingNo,
                    '_objectCodeInputInDataFetchStatus' => 1,
                    'SearchInput' => $trackingNo,
                    '_searchInputInDataFetchStatus' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CTT %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CTT %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !is_array($result['data']['ObjectEventsFromQuery'] ?? null)) {
            throw new LogisticsException('[CTT] 响应解析失败');
        }

        $info = $result['data']['ObjectEventsFromQuery'];
        $rawEvents = $info['Events']['List'] ?? [];
        if (!isset($info['Found']) || $info['Found'] !== true || !is_array($rawEvents) || $rawEvents === []) {
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
        // 事件若按时间降序返回则反转；升序输出，末条为最新
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ctt',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['State'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('ctt createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ctt createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ctt subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $event = (string) ($row['Event'] ?? '');
        $state = (string) ($row['State'] ?? '');
        $description = $event !== '' ? $event : $state;

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['DateTime'] ?? null),
            location: (string) ($row['Location'] ?? ''),
            description: $description,
            status: $this->mapStatus($state . ' ' . $event),
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

    /** 支持 'DD-MM-YYYY HH:MM'（CT 官网格式）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y g:i:s A', 'd-m-Y g:i:s A',
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
