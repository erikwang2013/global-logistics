<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

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
 * 增益速递（快递鸟编码 ZENY）适配器。
 *
 * 契约基于快递鸟（KDNiao）即时查询协议（RequestType=1002，ShipperCode=ZENY）：
 * https://www.kdniao.com/api-track ；增益速递官网未检索到公开开放 API，仅可通过
 * 第三方聚合平台查询。本适配器为 wave7 原定汇通快运（huitong）的备选：汇通快运
 * 并入百世体系后，快递鸟官方编码表（2024-11-25）中仅存"百世快递 HTKY"，已无
 * 汇通快运独立编码，故按批次计划替换为增益速递（ZENY）。
 *
 * VERIFIED-REQUIRED: 端点/签名字段（base64(md5(RequestData+AppKey, true))）按快递鸟公开
 * 协议实现，ShipperCode=ZENY 经快递鸟官方编码表确认（https://www.kdniao.com/message/detail/116110），
 * 状态文案映射与单号格式需实网验证（增益速递单号无公开统一格式，建议显式调用）。
 */
final class Zengyi implements CarrierInterface
{
    private const ENDPOINT = 'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx';

    private const SHIPPER_CODE = 'ZENY';

    /** 增益速递状态关键词 => 统一状态（以 AcceptStation 内容匹配，具体在前） */
    private const STATUS_MAP = [
        '异常|疑难|滞留|未签收' => TrackStatus::EXCEPTION,
        '签收' => TrackStatus::DELIVERED,
        '派送|派件' => TrackStatus::OUT_FOR_DELIVERY,
        '退回|退件' => TrackStatus::RETURNED,
        '揽收|收件' => TrackStatus::PENDING,
        '到达|出发|运输|中转|分拨|在途' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('zengyi.endpoint', self::ENDPOINT);
        $eBusinessId = (string) $this->config->get('zengyi.ebusiness_id');
        $appKey = (string) $this->config->get('zengyi.app_key');
        $customerName = (string) $this->config->get('zengyi.customer_name', '');

        $content = [
            'ShipperCode' => self::SHIPPER_CODE,
            'LogisticCode' => $trackingNo,
        ];
        if ($customerName !== '') {
            $content['CustomerName'] = $customerName;
        }

        $requestData = json_encode($content, JSON_UNESCAPED_UNICODE);
        $dataSign = base64_encode(md5($requestData . $appKey, true));

        $body = http_build_query([
            'RequestData' => $requestData,
            'EBusinessID' => $eBusinessId,
            'RequestType' => '1002',
            'DataSign' => $dataSign,
            'DataType' => '2',
        ]);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[ZENGYI %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[ZENGYI %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[ZENGYI] 响应解析失败');
        }

        if (($result['Success'] ?? false) !== true) {
            $this->throwForApiError((string) ($result['Reason'] ?? '未知错误'));
        }

        $traces = $result['Traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'zengyi',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['AcceptStation'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('zengyi createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('zengyi createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('zengyi subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row['AcceptTime'] ?? '')),
            location: (string) ($row['Remark'] ?? ''),
            description: (string) ($row['AcceptStation'] ?? ''),
            status: $this->mapStatus((string) ($row['AcceptStation'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $trackStatus) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $trackStatus;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', '!Y-m-d', 'Y/m/d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
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

    private function throwForApiError(string $message): never
    {
        foreach (['签名', '授权', '商户ID', 'EBusinessID', '单量'] as $keyword) {
            if (str_contains($message, $keyword)) {
                throw new AuthException(sprintf('[ZENGYI] %s', $message));
            }
        }

        throw new LogisticsException(sprintf('[ZENGYI] %s', $message));
    }
}
