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
 * 嘉里快递（Kerry Express，东南亚/泰国/越南）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（appId/appKey 换 token 的
 * 参数形态与 /track/v1/tracking/search 的响应字段名为公开文档推断；
 * openapi.kerryexpress.com 在本环境不可达）。
 * 凭据形态: App ID / API Key / 单号前缀（Prefix），由 Kerry Express 商务提供。
 */
final class Kerry implements CarrierInterface
{
    private const BASE_URL = 'https://openapi.kerryexpress.com';

    private const TOKEN_PATH = '/auth/token';

    private const TRACK_PATH = '/track/v1/tracking/search';

    /**
     * 轨迹描述关键词 => 统一状态（英文 + 泰文，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        'delivered|ส่งเรียบร้อย' => TrackStatus::DELIVERED,
        'out for delivery|กำลังจัดส่ง' => TrackStatus::OUT_FOR_DELIVERY,
        'return|ส่งคืน' => TrackStatus::RETURNED,
        'failed|exception|damage|ชำรุด' => TrackStatus::EXCEPTION,
        'picked up|รับพัสดุ' => TrackStatus::PENDING,
    ];

    private ?string $accessToken = null;

    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $base = (string) $this->config->get('kerry.base_url', self::BASE_URL);

        $request = new \GuzzleHttp\Psr7\Request('GET', $base . self::TRACK_PATH . '?' . http_build_query([
            'barcode' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token($base),
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            // token 可能过期，清除缓存以便下次重试
            $this->accessToken = null;
            $this->tokenExpiresAt = null;
            throw new AuthException(sprintf('[KERRY %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[KERRY %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[KERRY] 响应解析失败');
        }

        $success = $result['success'] ?? true;
        if ($success === false) {
            $this->throwForApiError((string) ($result['errorCode'] ?? ''), (string) ($result['errorMsg'] ?? ''));
        }

        $results = $result['data']['trackingResults'] ?? [];
        $track = is_array($results) ? ($results[0] ?? null) : null;
        $rawEvents = is_array($track) ? ($track['trackingEvents'] ?? []) : [];
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
        // 事件按时间升序，末条为最新；若返回降序则反转
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'kerry',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['trackingEventCode'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('kerry createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('kerry createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('kerry subscribe 待实现');
    }

    /**
     * 获取并缓存 access token（预留 60s 时钟偏移缓冲）；token 未过期时复用。
     */
    private function token(string $base): string
    {
        if ($this->accessToken !== null && ($this->tokenExpiresAt === null || $this->tokenExpiresAt > time())) {
            return $this->accessToken;
        }

        $body = json_encode([
            'appId' => (string) $this->config->get('kerry.app_id'),
            'appKey' => (string) $this->config->get('kerry.app_key'),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', $base . self::TOKEN_PATH, [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new AuthException(sprintf('[KERRY %d] token 获取失败', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !isset($result['accessToken']) || !is_string($result['accessToken'])) {
            throw new AuthException('[KERRY] token 获取失败（响应解析失败）');
        }

        $this->accessToken = $result['accessToken'];
        $this->tokenExpiresAt = isset($result['expiresIn']) && is_numeric($result['expiresIn'])
            ? time() + (int) $result['expiresIn'] - 60
            : null;

        return $this->accessToken;
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['trackingEventDescription'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['trackingEventDate'] ?? null),
            location: (string) ($row['location'] ?? $row['trackingEventLocation'] ?? ''),
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

        return TrackStatus::IN_TRANSIT;
    }

    /** 支持 'Y-m-d H:i:s'、'Y-m-d H:i'、ISO8601、'Y-m-d'，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', 'E401', 'E403', 'INVALID_TOKEN', 'TOKEN_EXPIRED'], true)) {
            throw new AuthException(sprintf('[KERRY %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[KERRY %s] %s', $code, $message));
    }
}
