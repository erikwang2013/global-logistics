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
 * 递四方（4PX Express）国际专线/小包适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证。
 * 文档: https://open.4px.com/apiInfo/api （FOP 开放平台；method=tr.order.tracking.get）
 * 认证: AppKey/AppSecret MD5 签名（公共参数按 key 升序去分隔符拼接 + body + appSecret，
 *       软件服务商需另配 access_token，OAuth 2.0 获取，签名时排除）
 */
final class FourPx implements CarrierInterface
{
    private const ENDPOINT = 'https://open.4px.com/router/api/service';
    private const METHOD_TRACK = 'tr.order.tracking.get';
    private const VERSION = '1.0';

    /** 轨迹状态关键词 => 统一状态（以 trackDesc/trackStatus 内容匹配，`|` 分隔同义关键词，EXCEPTION/RETURNED 优先） */
    private const STATUS_MAP = [
        '异常|滞留|失败|ON HOLD|FAILED|EXCEPTION' => TrackStatus::EXCEPTION,
        '退回|退件|退运|RETURN' => TrackStatus::RETURNED,
        '签收|妥投|DELIVERED' => TrackStatus::DELIVERED,
        '派送|投递|OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        '揽收|收件|接收|交寄|PICKED UP|RECEIVED|COLLECTED' => TrackStatus::PENDING,
        '到达|离开|运输|中转|清关|封发|交航|在途|IN TRANSIT|DEPARTED|ARRIVED|CUSTOMS' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appKey = (string) $this->config->get('fourpx.app_key');
        $appSecret = (string) $this->config->get('fourpx.app_secret');
        $accessToken = (string) $this->config->get('fourpx.access_token', '');
        $timestamp = (string) (int) (microtime(true) * 1000);

        $body = json_encode([
            'trackingNumber' => $trackingNo,
        ], JSON_UNESCAPED_UNICODE);

        // 公共参数按 key 升序（签名排除 access_token/language/sign），拼接 key+value 后接 body 与 appSecret 做 MD5
        $params = [
            'app_key' => $appKey,
            'format' => 'json',
            'method' => self::METHOD_TRACK,
            'timestamp' => $timestamp,
            'v' => self::VERSION,
        ];
        ksort($params);
        $signSource = '';
        foreach ($params as $key => $value) {
            $signSource .= $key . $value;
        }
        $signSource .= $body . $appSecret;
        $params['sign'] = md5($signSource);
        if ($accessToken !== '') {
            $params['access_token'] = $accessToken;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query($params), [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[4PX %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[4PX %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[4PX] 响应解析失败');
        }

        $code = (string) ($result['result'] ?? '');
        if ($code !== '1') {
            $this->throwForApiError($code, (string) ($result['msg'] ?? ''));
        }

        $data = $result['data'] ?? [];
        $traces = is_array($data) ? ($data['trackDetails'] ?? []) : [];
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
            carrierCode: 'fourpx',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['trackStatus'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('4PX createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('4PX createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('4PX subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['trackDesc'] ?? '');
        $statusText = (string) ($row['trackStatus'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row['trackTime'] ?? '')),
            location: (string) ($row['trackAddress'] ?? ''),
            description: $description !== '' ? $description : $statusText,
            status: $this->mapStatus($description . ' ' . $statusText),
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

    private function parseTime(string $value): ?\DateTimeImmutable
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

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403'], true)) {
            throw new AuthException(sprintf('[4PX %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[4PX %s] %s', $code, $message));
    }
}
