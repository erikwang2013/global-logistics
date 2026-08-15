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
 * 苏宁物流（SUNING）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于苏宁开放平台公开文档（sopRequest 公共参数
 * appMethod/appRequestTime/appKey/versionNo/signInfo，
 * signInfo = MD5(appSecret + appMethod + appRequestTime + appKey + versionNo + base64(请求报文)) 小写），
 * 轨迹查询方法（suning.logistics.crossbuytask.get）请求/响应字段名需实网验证。
 */
final class Suning implements CarrierInterface
{
    private const ENDPOINT = 'https://open.suning.com/api/http/sopRequest';

    private const APP_METHOD = 'suning.logistics.crossbuytask.get';

    /** 苏宁状态关键词 => 统一状态（以 statusDescription 内容匹配） */
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
        $appKey = (string) $this->config->get('suning.app_key');
        $appSecret = (string) $this->config->get('suning.app_secret');
        $versionNo = (string) $this->config->get('suning.version_no', 'v1.2');
        $appRequestTime = date('Y-m-d H:i:s');

        $body = json_encode([
            'sn_request' => [
                'sn_body' => [
                    'queryCrossbuyTask' => [
                        'logisticExpressId' => $trackingNo,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $signInfo = md5($appSecret . self::APP_METHOD . $appRequestTime . $appKey . $versionNo . base64_encode($body));

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query([
            'appMethod' => self::APP_METHOD,
            'appRequestTime' => $appRequestTime,
            'format' => 'json',
            'appKey' => $appKey,
            'versionNo' => $versionNo,
            'signInfo' => $signInfo,
        ]), [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[SUNING %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[SUNING %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[SUNING] 响应解析失败');
        }

        $content = $result['sn_responseContent'] ?? null;
        if (!is_array($content)) {
            throw new LogisticsException('[SUNING] 响应解析失败');
        }

        if (isset($content['sn_error']) && is_array($content['sn_error'])) {
            $this->throwForApiError(
                (string) ($content['sn_error']['error_code'] ?? ''),
                (string) ($content['sn_error']['error_msg'] ?? ''),
            );
        }

        $traces = $content['sn_body']['queryCrossbuyTask'] ?? [];
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
            carrierCode: 'suning',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['statusDescription'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('SUNING createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('SUNING createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('SUNING subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['statusDescription'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keywords => $mapped) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($description, $keyword)) {
                    $status = $mapped;
                    break 2;
                }
            }
        }

        $occurredAt = null;
        $realCompleteDate = (string) ($row['realCompleteDate'] ?? '');
        $realCompleteTime = (string) ($row['realCompleteTime'] ?? '');
        if ($realCompleteDate !== '') {
            $occurredAt = $this->parseTime($realCompleteDate . ($realCompleteTime !== '' ? ' ' . $realCompleteTime : ''));
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['siteDescription'] ?? ''),
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
        if (in_array($code, ['sys.check.app-sign:null', 'sys.check.app-sign:error'], true)) {
            throw new AuthException(sprintf('[SUNING %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[SUNING %s] %s', $code, $message));
    }
}
