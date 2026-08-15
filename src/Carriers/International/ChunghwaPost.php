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
 * 中华邮政（Chunghwa Post，台湾）国际邮件查询（pstmail 网页查询底层 JSON 接口，
 * GET CSController?cmd=querymail，公开无认证）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（CSController/querymail
 * 为查询页前端推断的底层接口，响应字段名（大写）与 STATUS 取值需实测确认；
 * 接口仅返回最新一笔状态，无法获取完整轨迹时间线）。
 * 文档: https://postserv.post.gov.tw/pstmail/main_mail.html
 */
final class ChunghwaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://postserv.post.gov.tw/webpost/CSController';

    /**
     * 状态文本关键词 => 统一状态（小写匹配，`|` 分隔同义关键词）。
     * '已投遞'（已投递）必须先于 '投遞中'（投递中，前缀重叠）。
     */
    private const STATUS_MAP = [
        '已投遞|delivered' => TrackStatus::DELIVERED,
        '投遞中|派送中|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        '退回|退件|return' => TrackStatus::RETURNED,
        '無法|failed|exception|held' => TrackStatus::EXCEPTION,
        '交寄|收寄|picked up|collected' => TrackStatus::PENDING,
        '運送中|轉運中|出口|進口|途中|in transit|transit|received|arrived|departed|sorted' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('chunghwa-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'cmd' => 'querymail',
            'mailNo' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CHUNGHWA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CHUNGHWA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CHUNGHWA-POST] 响应解析失败');
        }
        if (($result['STATUS'] ?? null) === '0' || str_contains((string) ($result['MSG'] ?? ''), '查無')) {
            throw new TrackingNotFoundException($trackingNo);
        }
        if (!isset($result['MAILNO']) || $result['MAILNO'] === '') {
            throw new TrackingNotFoundException($trackingNo);
        }

        $description = trim((string) ($result['TRACKINFO'] ?? ''));
        $statusText = $description . ' ' . (string) ($result['MAILTYPE'] ?? '');
        $event = new TrackingEvent(
            occurredAt: $this->parseTime($result['DELIVERYDATE'] ?? $result['RECEIVETIME'] ?? $result['SENDDATE'] ?? null),
            location: trim((string) ($result['LOCATIONNAME'] ?? '')),
            description: $description !== '' ? $description : '郵件查詢',
            status: $this->mapStatus($statusText),
            raw: $result,
        );

        $events = [$event];
        $latest = $event;

        return new Tracking(
            carrierCode: 'chunghwa-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($result['STATUS'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('chunghwa-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('chunghwa-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('chunghwa-post subscribe 待实现');
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

    /** 支持 'Y-m-d H:i:s'、'Y/m/d H:i:s'、纯日期等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y/m/d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
