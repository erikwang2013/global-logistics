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
 * 丰网速运（顺丰旗下加盟网络，快递鸟编码 FWX）适配器。
 *
 * 契约基于快递鸟（KDNiao）即时查询协议（RequestType=1002，ShipperCode=FWX）：
 * https://www.kdniao.com/api-track ；丰网速运官网 fwx-network.com 已关闭，未检索到公开
 * 开放 API，仅第三方聚合平台提供查询；顺丰系单号查询需寄/收件人手机号后四位（CustomerName）。
 *
 * VERIFIED-REQUIRED: 端点/签名字段（base64(md5(RequestData+AppKey, true))）按快递鸟公开
 * 协议实现，ShipperCode=FWX 与 CustomerName 取值需实网验证（丰网速运已并入顺丰体系，
 * 部分单号可被顺丰 SF 规则截获，建议显式调用）。
 */
final class Fengwang implements CarrierInterface
{
    private const ENDPOINT = 'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx';

    private const SHIPPER_CODE = 'FWX';

    /** 丰网速运状态关键词 => 统一状态（以 AcceptStation 内容匹配，具体在前） */
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
        $endpoint = (string) $this->config->get('fengwang.endpoint', self::ENDPOINT);
        $eBusinessId = (string) $this->config->get('fengwang.ebusiness_id');
        $appKey = (string) $this->config->get('fengwang.app_key');
        $customerName = (string) $this->config->get('fengwang.customer_name', '');

        $content = [
            'ShipperCode' => self::SHIPPER_CODE,
            'LogisticCode' => $trackingNo,
        ];
        if ($customerName !== '') {
            $content['CustomerName'] = $customerName; // 顺丰系需要寄/收件人手机号后四位
        }

        $requestData = json_encode($content, JSON_UNESCAPED_UNICODE);
        $dataSign = base64_encode(md5($requestData . $appKey, true));

        $body = http_build_query([
            'RequestData' => $requestData, // http_build_query 做一次 URL 编码
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
            throw new AuthException(sprintf('[FENGWANG %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[FENGWANG %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[FENGWANG] 响应解析失败');
        }

        if (($result['Success'] ?? false) !== true) {
            $this->throwForApiError((string) ($result['Reason'] ?? '未知错误'));
        }

        $traces = $result['Traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 快递鸟返回轨迹按时间升序；若承运商返回降序则反转
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
            carrierCode: 'fengwang',
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
        throw new LogisticsException('fengwang createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('fengwang createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('fengwang subscribe 待实现');
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

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d H:i:s'] as $format) {
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
                throw new AuthException(sprintf('[FENGWANG] %s', $message));
            }
        }

        throw new LogisticsException(sprintf('[FENGWANG] %s', $message));
    }
}
