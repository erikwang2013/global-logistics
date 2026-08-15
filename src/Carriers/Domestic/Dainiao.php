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
 * 丹鸟（菜鸟直送）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于丹鸟开放平台（openapi.danniao.com）公开 EDI 网关文档模式
 * （form 表单提交 logistic_provider_id/logistics_interface/data_digest，
 * data_digest = Base64(Md5(logistics_interface + secretKey))，与菜鸟 TMS 一致），
 * 生产端点（edi.xpm.cainiao.com）与轨迹字段名需实网验证。
 * 文档: http://openapi.danniao.com/docs/b2c/
 */
final class Dainiao implements CarrierInterface
{
    private const ENDPOINT = 'https://edi.xpm.cainiao.com/ext/gateway/ediStandardTraceQuery/ediStandardTraceQuery/api';

    /** 丹鸟状态关键词 => 统一状态（以 desc 内容匹配） */
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
        $providerId = (string) $this->config->get('dainiao.logistic_provider_id');
        $secretKey = (string) $this->config->get('dainiao.secret_key');
        $endpoint = (string) $this->config->get('dainiao.endpoint', self::ENDPOINT);

        $logisticsInterface = json_encode([
            'mailNo' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);
        $dataDigest = base64_encode(md5($logisticsInterface . $secretKey, true));

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'logistic_provider_id' => $providerId,
            'logistics_interface' => $logisticsInterface,
            'data_digest' => $dataDigest,
        ]));

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[DAINIAO %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[DAINIAO %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[DAINIAO] 响应解析失败');
        }

        $success = $result['success'] ?? null;
        if ($success === false || $success === 'false' || $success === 0 || $success === '0') {
            $this->throwForApiError(
                (string) ($result['errorCode'] ?? $result['error_code'] ?? ''),
                (string) ($result['errorMsg'] ?? $result['error_msg'] ?? ''),
            );
        }

        $traces = $result['data']['traces'] ?? $result['logisticsTrajectories'] ?? [];
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
            carrierCode: 'dainiao',
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
        throw new LogisticsException('dainiao createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('dainiao createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('dainiao subscribe 待实现');
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
        if (in_array($code, ['SIGN_CHECK_FAIL', 'sign-check-failure', 'INVALID_SIGN', 'AUTH_FAILED', 'AUTH_ERROR'], true)) {
            throw new AuthException(sprintf('[DAINIAO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[DAINIAO %s] %s', $code, $message));
    }
}
