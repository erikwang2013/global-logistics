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
 * 信丰（Xf）适配器。
 *
 * 契约基于快递鸟（KDNiao）即时查询协议（RequestType=1002，ShipperCode=XFEXPRESS）：
 * https://www.kdniao.com/api-track ；信丰官网 xf-express.com.cn 仅提供网页/微信公众号
 * 货物跟踪（http://xf-express.com.cn/customer/trace），未检索到公开开放平台 API。
 *
 * VERIFIED-REQUIRED: 端点/签名字段（base64(md5(RequestData+AppKey, true))）按快递鸟
 * 公开协议实现，信丰官方直连接口需实网验证。
 */
final class Xf implements CarrierInterface
{
    private const ENDPOINT = 'https://api.kdniao.com/Ebusiness/EbusinessOrderHandle.aspx';

    private const SHIPPER_CODE = 'XFEXPRESS';

    /** 信丰状态关键词 => 统一状态（以 AcceptStation 内容匹配，具体在前） */
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
        $endpoint = (string) $this->config->get('xf.endpoint', self::ENDPOINT);
        $eBusinessId = (string) $this->config->get('xf.ebusiness_id');
        $appKey = (string) $this->config->get('xf.app_key');

        $requestData = json_encode([
            'ShipperCode' => self::SHIPPER_CODE,
            'LogisticCode' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);
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
            throw new AuthException(sprintf('[XF %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[XF %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[XF] 响应解析失败');
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
        if (count($events) > 1
            && $events[0]->occurredAt !== null
            && $events[count($events) - 1]->occurredAt !== null
            && $events[0]->occurredAt > $events[count($events) - 1]->occurredAt) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'xf',
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
        throw new LogisticsException('XF createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('XF createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('XF subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['AcceptTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['AcceptStation'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keywords => $mapped) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($description, $keyword)) {
                    $status = $mapped;
                    break 2;
                }
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['Remark'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $message): never
    {
        foreach (['签名', '授权', '商户ID', 'EBusinessID', '单量'] as $keyword) {
            if (str_contains($message, $keyword)) {
                throw new AuthException(sprintf('[XF] %s', $message));
            }
        }

        throw new LogisticsException(sprintf('[XF] %s', $message));
    }
}
