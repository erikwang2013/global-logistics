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
 * 云途物流（YunExpress）国际专线适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证。
 * 文档: https://open.yunexpress.cn （云途开放平台 OpenAPI）
 * 认证: OAuth2 client_credentials（appId/appSecret/sourceKey 换取 accessToken，约 50 分钟有效），
 *       请求头携带 HMAC-SHA256 签名（body=...&date=毫秒&method=GET&uri=...，Base64）+ token + date
 * 轨迹: GET /v1/track-service/info/get?order_number=云途运单号（旧版 OMS 接口为 /api/Tracking/GetTrackInfo，未采用）
 */
final class YunExpress implements CarrierInterface
{
    private const BASE_URL = 'https://openapi.yunexpress.cn';
    private const TOKEN_PATH = '/openapi/oauth2/token';
    private const TRACK_PATH = '/v1/track-service/info/get';

    /** 轨迹状态关键词 => 统一状态（以 description 内容匹配，`|` 分隔同义关键词，EXCEPTION/RETURNED 优先） */
    private const STATUS_MAP = [
        '异常|滞留|失败|EXCEPTION|ON HOLD|FAILED' => TrackStatus::EXCEPTION,
        '退回|退件|退运|RETURN' => TrackStatus::RETURNED,
        '签收|妥投|DELIVERED' => TrackStatus::DELIVERED,
        '派送|投递|OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        '揽收|收件|接收|交寄|PICKED UP|RECEIVED|COLLECTED' => TrackStatus::PENDING,
        '运输|到达|离开|中转|清关|在途|IN TRANSIT|DEPARTED|ARRIVED|CUSTOMS' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appId = (string) $this->config->get('yunexpress.app_id');
        $appSecret = (string) $this->config->get('yunexpress.app_secret');
        $sourceKey = (string) $this->config->get('yunexpress.source_key', '');

        $accessToken = $this->fetchAccessToken($appId, $appSecret, $sourceKey);

        $uri = self::TRACK_PATH . '?' . http_build_query(['order_number' => $trackingNo]);
        $timestamp = (string) (int) (microtime(true) * 1000);
        $sign = base64_encode(hash_hmac('sha256', sprintf('body=&date=%s&method=GET&uri=%s', $timestamp, $uri), $appSecret, true));

        $request = new \GuzzleHttp\Psr7\Request('GET', self::BASE_URL . $uri, [
            'Content-Type' => 'application/json;charset=utf-8',
            'Accept-Language' => 'zh-CN',
            'sign' => $sign,
            'token' => $accessToken,
            'date' => $timestamp,
        ]);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[YUNEXPRESS %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[YUNEXPRESS %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[YUNEXPRESS] 响应解析失败');
        }

        $code = $result['code'] ?? $result['Code'] ?? '';
        if (!in_array($code, ['0', '0000', 0], true)) {
            $this->throwForApiError((string) $code, (string) ($result['message'] ?? $result['Message'] ?? ''));
        }

        $data = $result['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        $trackInfo = $data['track_info'] ?? [];
        $traces = is_array($trackInfo) && isset($trackInfo[0]) && is_array($trackInfo[0])
            ? ($trackInfo[0]['track_events'] ?? [])
            : ($data['track_events'] ?? []);
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
        $events = self::sortEvents($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yunexpress',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) $code,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('YUNEXPRESS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('YUNEXPRESS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('YUNEXPRESS subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['description'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row['event_date'] ?? ''), (string) ($row['time_zone'] ?? '')),
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $text): TrackStatus
    {
        $upper = strtoupper($text);
        foreach (self::STATUS_MAP as $keywords => $mapped) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($upper, $keyword)) {
                    return $mapped;
                }
            }
        }

        return TrackStatus::IN_TRANSIT;
    }

    /** 支持 'Y-m-d H:i:s'、'Y-m-d H:i'、'Y-m-d' 及 ISO8601；time_zone 存在时尽量补充时区偏移 */
    private function parseTime(string $value, string $timeZone = ''): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }
        if ($timeZone !== '' && preg_match('/^[+-]\d{2}:?\d{2}$/', $timeZone) === 1) {
            foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
                $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone($timeZone));
                if ($date !== false) {
                    return $date;
                }
            }
        }

        return null;
    }

    /** 接口可能按时间降序返回（最新在前），统一为升序，末条为最新 */
    /** @param TrackingEvent[] $events */
    private static function sortEvents(array $events): array
    {
        $count = count($events);
        if ($count < 2) {
            return $events;
        }
        $first = $events[0]->occurredAt;
        $last = $events[$count - 1]->occurredAt;

        return $first !== null && $last !== null && $first > $last
            ? array_reverse($events)
            : $events;
    }

    private function fetchAccessToken(string $appId, string $appSecret, string $sourceKey): string
    {
        $body = [
            'grantType' => 'client_credentials',
            'appId' => $appId,
            'appSecret' => $appSecret,
        ];
        if ($sourceKey !== '') {
            $body['sourceKey'] = $sourceKey;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', self::BASE_URL . self::TOKEN_PATH, [
            'Content-Type' => 'application/json',
        ], json_encode($body, JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[YUNEXPRESS %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[YUNEXPRESS %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new AuthException('[YUNEXPRESS] Token 响应解析失败');
        }

        $token = $result['accessToken'] ?? $result['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new AuthException('[YUNEXPRESS] 未获取到 accessToken');
        }

        return $token;
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403'], true)) {
            throw new AuthException(sprintf('[YUNEXPRESS %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[YUNEXPRESS %s] %s', $code, $message));
    }
}
