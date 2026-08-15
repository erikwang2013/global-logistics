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
 * 中国邮政（CHINA-POST）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式（api.ems.com.cn 国内协议客户开放平台：
 * form 表单提交，Sign = MD5(AppID + RequestData + Timestamp + AppSecret) 大写），
 * 端点、轨迹字段名需实网验证。
 */
final class ChinaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://api.ems.com.cn/amp-prod-api/f/amp/api/open';

    /** 邮政状态关键词 => 统一状态（以 remark 内容匹配；邮政以 收寄 表示揽收、以 妥投 表示已投递） */
    private const STATUS_MAP = [
        '签收|妥投' => TrackStatus::DELIVERED,
        '退回|退件' => TrackStatus::RETURNED,
        '异常|疑难|滞留|失败' => TrackStatus::EXCEPTION,
        '派送|投递' => TrackStatus::OUT_FOR_DELIVERY,
        '收寄|揽收' => TrackStatus::PENDING,
        '运输|转运|到达|离开|发运|出口|进口' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appId = (string) $this->config->get('china-post.app_id');
        $appSecret = (string) $this->config->get('china-post.app_secret');
        $timestamp = (string) time();
        $requestData = json_encode([
            'mailNo' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);
        $sign = strtoupper(md5($appId . $requestData . $timestamp . $appSecret));

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'appId' => $appId,
            'timestamp' => $timestamp,
            'requestData' => $requestData,
            'sign' => $sign,
        ]));

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[CHINA-POST %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[CHINA-POST %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[CHINA-POST] 响应解析失败');
        }

        $code = (string) ($result['code'] ?? '');
        if ($code !== '0') {
            $this->throwForApiError($code, (string) ($result['message'] ?? $result['msg'] ?? ''));
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
            carrierCode: 'china-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['remark'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('CHINA-POST createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('CHINA-POST createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('CHINA-POST subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['remark'] ?? '');
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
            occurredAt: $this->parseTime((string) ($row['acceptTime'] ?? '')),
            location: (string) ($row['acceptAddress'] ?? ''),
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
        if (in_array($code, ['401', '403', '10001', '10002', '10003'], true)) {
            throw new AuthException(sprintf('[CHINA-POST %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[CHINA-POST %s] %s', $code, $message));
    }
}
