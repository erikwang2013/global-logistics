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
 * 俄罗斯邮政（russia-post）适配器：国际件查询（tracking.pochta.ru 单件查询 REST API，HTTP Basic 认证，JSON 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（operation-history 端点、
 * operationHistory/operationParameters 事件结构与操作类型名称均为公开文档推断）。
 * 文档: https://tracking.pochta.ru/specification
 */
final class RussiaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://tracking.pochta.ru/tracking-web/api/operation-history';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
            'language' => (string) ($options['language'] ?? 'RUS'),
            'messageType' => (int) ($options['messageType'] ?? 1),
        ]), [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config->get('russia-post.login') . ':' . (string) $this->config->get('russia-post.password'),
            ),
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[RUSSIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[RUSSIA-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[RUSSIA-POST] 响应解析失败');
        }

        $this->throwForApiError($result);

        $events = [];
        foreach ($result['operationHistory'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'russia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->description,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('russia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('russia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('russia-post subscribe 待实现');
    }

    private function throwForApiError(array $result): void
    {
        $message = $result['errorMessage'] ?? $result['message'] ?? null;
        if (is_string($message) && $message !== '') {
            throw new LogisticsException('[RUSSIA-POST] ' . $message);
        }

        $error = $result['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            throw new LogisticsException('[RUSSIA-POST] ' . $error['message']);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = '';
        $date = null;
        $parameters = $row['operationParameters'] ?? null;
        if (is_array($parameters)) {
            $parts = [];
            foreach (['operType', 'operAttr'] as $key) {
                $sub = $parameters[$key] ?? null;
                if (is_array($sub) && isset($sub['name'])) {
                    $parts[] = (string) $sub['name'];
                }
            }
            $description = implode(' ', $parts);
            $date = $parameters['operationDate'] ?? null;
        }

        $addressParameters = $row['addressParameters'] ?? null;
        $location = is_array($addressParameters) ? (string) ($addressParameters['operationAddress'] ?? '') : '';

        return new TrackingEvent(
            occurredAt: $this->parseTime($date),
            location: $location,
            description: $description,
            status: $this->mapStatus($description),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        if ($this->hasKeyword($description, ['место вручения', 'на доставке', 'курьер'])) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if ($this->hasKeyword($description, ['вручен', 'получено адресатом', 'доставлено', 'выдано'])) {
            return TrackStatus::DELIVERED;
        }
        if ($this->hasKeyword($description, ['возврат', 'обратно отправителю'])) {
            return TrackStatus::RETURNED;
        }
        if ($this->hasKeyword($description, ['неудачн', 'задерж', 'истек', 'хранится', 'невостребовано', 'досыл'])) {
            return TrackStatus::EXCEPTION;
        }
        if ($this->hasKeyword($description, ['приём', 'принято', 'ожидает', 'подготовк', 'зарегистрир'])) {
            return TrackStatus::PENDING;
        }
        if ($this->hasKeyword($description, ['сортировк', 'транзит', 'пересылк', 'перевозк', 'обработк',
            'покинуло', 'прибыло', 'отправлено', 'направлено'])) {
            return TrackStatus::IN_TRANSIT;
        }

        return TrackStatus::UNKNOWN;
    }

    /**
     * 大小写不敏感关键词匹配（strtolower 仅处理 ASCII，西里尔字母需 PCRE /u + /i）。
     *
     * @param string[] $needles
     */
    private function hasKeyword(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/' . preg_quote($needle, '/') . '/ui', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 多格式时间解析：ISO8601 带时区偏移（含 Z 与毫秒）、无偏移；全部失败返回 null。
     */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.v', 'Y-m-d\TH:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt;
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
}
