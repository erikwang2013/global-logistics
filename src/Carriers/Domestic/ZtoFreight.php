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
 * 中通快运（ZTO Freight，快递鸟编码 ZTOKY）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于中通开放平台（zop.zto.com）公开对接流程模式
 * （company_id + data_digest = Base64(Md5(业务报文 + appSecret)) 签名），
 * 快运轨迹查询通常需收件人手机号后四位；生产端点与响应字段名需实网验证。
 * 文档: https://zop.zto.com
 */
final class ZtoFreight implements CarrierInterface
{
    private const ENDPOINT = 'https://api.zto.com/zto.merchant.waybill.track.query';

    /** 中通快运状态关键词 => 统一状态（以 desc 内容匹配） */
    private const STATUS_MAP = [
        '签收' => TrackStatus::DELIVERED,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '揽收|收件' => TrackStatus::PENDING,
        '到达|出发|运输|中转|分拨' => TrackStatus::IN_TRANSIT,
        '退回' => TrackStatus::RETURNED,
        '异常|滞留' => TrackStatus::EXCEPTION,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $companyId = (string) $this->config->get('zto-freight.company_id');
        $appSecret = (string) $this->config->get('zto-freight.app_secret');
        $phoneSuffix = (string) $this->config->get('zto-freight.phone_suffix', '');
        $endpoint = (string) $this->config->get('zto-freight.endpoint', self::ENDPOINT);

        $body = json_encode([
            'billCode' => $trackingNo,
            'phoneSuffix' => $phoneSuffix,
        ], JSON_UNESCAPED_UNICODE);
        $dataDigest = base64_encode(md5($body . $appSecret, true));

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'company_id' => $companyId,
            'data_digest' => $dataDigest,
            'request_data' => $body,
        ]));

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[ZTOFREIGHT %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[ZTOFREIGHT %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[ZTOFREIGHT] 响应解析失败');
        }

        $success = $result['status'] ?? null;
        if ($success === false || $success === 'false' || $success === 0 || $success === '0') {
            $this->throwForApiError(
                (string) ($result['statusCode'] ?? ''),
                (string) ($result['message'] ?? ''),
            );
        }

        $traces = $result['data']['traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新；若承运商返回降序则反转
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
            carrierCode: 'zto-freight',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['desc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('zto-freight createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('zto-freight createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('zto-freight subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['desc'] ?? '');
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
            occurredAt: $this->parseTime((string) ($row['time'] ?? '')),
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
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

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', 'E403', 'SIGN_CHECK_FAIL', 'AUTH_FAILED'], true)) {
            throw new AuthException(sprintf('[ZTOFREIGHT %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[ZTOFREIGHT %s] %s', $code, $message));
    }
}
