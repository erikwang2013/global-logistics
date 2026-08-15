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
 * 万邑通（Winit，跨境仓配/ISP 小包）轨迹查询适配器。
 *
 * 契约基于万邑通开放平台官方文档 tracking.getOrderTracking（OpenAPI 网关统一入口，
 * POST JSON，app_key + sign MD5 签名；单号支持 ISP 跟踪号或 winit 订单号，逗号分隔批量）。
 * 文档: https://developer.winit.com.cn/document/detail/id/71.html
 *
 * VERIFIED-REQUIRED: 端点/action/字段名取自官方文档；签名算法按万邑通通用规则实现
 * （除 sign 外参数按 ASCII 升序拼接为 k1=v1&k2=v2 再追加 app_secret，MD5 转大写），
 * client_sign 同理追加 client_secret（可选），需实网验证；status 事件词为英文/中文混合，
 * STATUS_MAP 关键词按官方示例轨迹推断，需按实际返回补充。
 */
final class Winit implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.winit.com.cn/openapi/service';

    private const ACTION = 'tracking.getOrderTracking';

    /**
     * 轨迹描述/状态关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * 'out for delivery|派送中' 必须先于 'delivered'（包含关系按具体在前）。
     */
    private const STATUS_MAP = [
        'out for delivery|派送中|派件中' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|派送完成|已签收|签收' => TrackStatus::DELIVERED,
        'returned|return|退回|退件' => TrackStatus::RETURNED,
        'exception|failed|异常|失败|延误' => TrackStatus::EXCEPTION,
        'in transit|transit|enroute|在途|运输中|已发货|departed|arrived|到达|离开' => TrackStatus::IN_TRANSIT,
        'submitted|ready|created|pending|pickup|揽收|已揽收|待揽收|已下单|下单' => TrackStatus::PENDING,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('winit.endpoint', self::ENDPOINT);
        $appKey = (string) $this->config->get('winit.app_key');
        $appSecret = (string) $this->config->get('winit.app_secret');
        $clientId = (string) $this->config->get('winit.client_id', '');
        $clientSecret = (string) $this->config->get('winit.client_secret', '');
        $platform = (string) $this->config->get('winit.platform', 'OWNERERP');
        $language = (string) $this->config->get('winit.language', 'zh_CN');

        $data = json_encode(['trackingNOs' => $trackingNo], JSON_UNESCAPED_UNICODE);
        $timestamp = date('Y-m-d H:i:s');

        $params = [
            'action' => self::ACTION,
            'app_key' => $appKey,
            'data' => $data,
            'format' => 'json',
            'language' => $language,
            'platform' => $platform,
            'sign_method' => 'md5',
            'timestamp' => $timestamp,
            'version' => '1.0',
        ];
        if ($clientId !== '') {
            $params['client_id'] = $clientId;
        }
        ksort($params);

        $params['client_sign'] = $this->sign($params, $clientSecret);
        $params['sign'] = $this->sign($params, $appSecret);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/json',
        ], json_encode($params, JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[WINIT %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[WINIT %d] 接口错误', $statusCode));
        }

        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[WINIT] 响应解析失败');
        }

        $code = $result['code'] ?? null;
        if ($code !== '0' && $code !== 0) {
            $this->throwForApiError((string) $code, (string) ($result['msg'] ?? '未知错误'));
        }

        $rows = $result['data'] ?? [];
        if (!is_array($rows) || $rows === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 官方支持多单号批量查询，优先取与请求单号匹配的行，其次取首行
        $row = null;
        foreach ($rows as $candidate) {
            if (is_array($candidate) && strcasecmp((string) ($candidate['trackingNo'] ?? ''), $trackingNo) === 0) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null && is_array($rows[0])) {
            $row = $rows[0];
        }
        if ($row === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $row['trace'] ?? [];
        if (!is_array($rawEvents) || $rawEvents === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($rawEvents as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $events[] = $this->mapEvent($trace);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'winit',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($row['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('winit createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('winit createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('winit subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $eventStatus = (string) ($row['eventStatus'] ?? '');
        $description = (string) ($row['eventDescription'] ?? '');
        if ($description === '') {
            $description = $eventStatus;
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime((string) ($row['date'] ?? '')),
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $this->mapStatus($description . ' ' . $eventStatus),
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

    /** 支持 'Y-m-d H:i:s' 等，解析失败返回 null */
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

    /**
     * 万邑通签名：参数按 ASCII 升序拼接为 k1=v1&k2=v2（不含签名自身），追加密钥后 MD5 转大写。
     *
     * @param array<string, mixed> $params
     */
    private function sign(array $params, string $secret): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'client_sign') {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return strtoupper(md5(implode('&', $pairs) . $secret));
    }

    private function throwForApiError(string $code, string $message): never
    {
        foreach (['签名', '授权', 'token', 'Token', '密钥'] as $keyword) {
            if (str_contains($message, $keyword)) {
                throw new AuthException(sprintf('[WINIT %s] %s', $code, $message));
            }
        }

        throw new LogisticsException(sprintf('[WINIT %s] %s', $code, $message));
    }
}
