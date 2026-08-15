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
 * 菜鸟国际（菜鸟全球/国际小包，Cainiao Global）适配器。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（global.detail.json 为
 * 菜鸟全球官网追踪页所用公开查询端点，本环境受地域/风控限制无法实网返回，
 * 响应字段名依据公开第三方 SDK 文档推断；开放平台正式对接建议以
 * 菜鸟物流云 TMS_LOGISITICS_QUERY 的 appKey/sign 签名为准）。
 * 文档: https://global.cainiao.com
 */
final class CainiaoIntl implements CarrierInterface
{
    private const ENDPOINT = 'https://global.cainiao.com/global/detail.json';

    /**
     * statusDesc 关键词 => 统一状态（中文，`|` 分隔同义关键词）。
     */
    private const STATUS_MAP = [
        '签收|已签收|妥投' => TrackStatus::DELIVERED,
        '派送|投递|配送中' => TrackStatus::OUT_FOR_DELIVERY,
        '揽收|收件|已取件' => TrackStatus::PENDING,
        '退回|退件' => TrackStatus::RETURNED,
        '异常|滞留|失败' => TrackStatus::EXCEPTION,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('cainiao-intl.endpoint', self::ENDPOINT);

        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'mailNoList' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CAINIAO-INTL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CAINIAO-INTL %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CAINIAO-INTL] 响应解析失败');
        }

        $success = $result['success'] ?? true;
        if ($success === false) {
            $this->throwForApiError((string) ($result['errorCode'] ?? ''), (string) ($result['errorMsg'] ?? ''));
        }

        $mailNos = $result['data']['mailNos'] ?? [];
        $mailNo = is_array($mailNos) ? ($mailNos[0] ?? null) : null;
        $rawEvents = is_array($mailNo) ? ($mailNo['traceDTOList'] ?? []) : [];
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
            carrierCode: 'cainiao-intl',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('cainiao-intl createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('cainiao-intl createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('cainiao-intl subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['statusDesc'] ?? $row['detail'] ?? '');
        $location = trim((string) ($row['cityName'] ?? '') . ' ' . (string) ($row['orgName'] ?? ''));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['timeDesc'] ?? null),
            location: $location,
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($description, $keyword)) {
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
        if (in_array($code, ['401', '403', 'AUTH_FAIL', 'TOKEN_EXPIRED'], true)) {
            throw new AuthException(sprintf('[CAINIAO-INTL %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[CAINIAO-INTL %s] %s', $code, $message));
    }
}
