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
 * 顺心捷达（国内零担快运）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于官方开放平台公开文档（网关/通用参数/签名方式已确认；
 * 路由查询接口的业务参数 data 结构、dataDigest 签名拼接顺序、data.routes 字段名
 * 均按行业 EDI 模式推断，需开通账号后实网验证）。
 * 文档: https://www.sxjdfreight.com/platform/openInterfaceDoc.html
 */
final class Sxjd implements CarrierInterface
{
    private const ENDPOINT = 'https://oms.sxjdfreight.com/api/gateway/service';

    /**
     * 轨迹 remark 关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        '已签收|签收' => TrackStatus::DELIVERED,
        '派送中|派件中' => TrackStatus::OUT_FOR_DELIVERY,
        '退回|退件|返程' => TrackStatus::RETURNED,
        '异常|滞留|拒收|破损' => TrackStatus::EXCEPTION,
        '已揽收|揽收|已收件' => TrackStatus::PENDING,
        '运输中|在途|到达|中转|发往|分拨|装车|卸车' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('sxjd.endpoint', self::ENDPOINT);
        $appKey = (string) $this->config->get('sxjd.app_key');
        $customerCode = (string) $this->config->get('sxjd.customer_code');

        // data 为业务参数 JSON 字符串；dataDigest 签名拼接（MD5+BASE64）VERIFIED-REQUIRED
        $data = json_encode(['waybillNo' => $trackingNo], JSON_UNESCAPED_UNICODE);
        $body = http_build_query([
            'apiType' => 'CP',
            'appKey' => $appKey,
            'sxCustomerCode' => $customerCode,
            'dataDigest' => base64_encode(md5($data . $appKey, true)),
            'timestamp' => (int) round(microtime(true) * 1000),
            'messageId' => bin2hex(random_bytes(8)),
            'data' => $data,
        ]);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SXJD %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SXJD %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[SXJD] 响应解析失败');
        }
        if (($result['success'] ?? false) !== true) {
            throw new LogisticsException(sprintf(
                '[SXJD %s] %s',
                (string) ($result['code'] ?? ''),
                (string) ($result['message'] ?? '接口错误'),
            ));
        }

        $dataResult = $result['data'] ?? [];
        $rawEvents = is_array($dataResult) ? ($dataResult['routes'] ?? []) : [];
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
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'sxjd',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['opCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('sxjd createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('sxjd createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('sxjd subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['remark'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['acceptTime'] ?? null),
            location: (string) ($row['acceptAddress'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
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

    /** 支持 ISO8601 带时区（含毫秒/时区偏移）、'Y-m-d H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
