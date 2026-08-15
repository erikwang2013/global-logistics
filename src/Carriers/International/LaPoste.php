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
 * 法国邮政（la-poste）适配器：Colissimo 国际件查询（La Poste Suivi API v2，X-Okapi-Key 认证，JSON 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（returnCode 取值、
 * shipment.event 结构与事件代码 DI1/DI2 均为公开文档推断）。
 * 文档: https://developer.laposte.fr/products/suivi/2
 */
final class LaPoste implements CarrierInterface
{
    private const ENDPOINT = 'https://api.laposte.fr/suivi/v2/idships/{trackingNo}';

    /** La Poste 事件代码 => 统一状态（DI1=Distribué、DI2=Distribué au destinataire） */
    private const CODE_MAP = [
        'DI1' => TrackStatus::DELIVERED,
        'DI2' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), self::ENDPOINT) . '?' . http_build_query([
            'lang' => (string) ($options['lang'] ?? 'fr_FR'),
        ]), [
            'X-Okapi-Key' => (string) $this->config->get('la-poste.api_key'),
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[LA-POSTE %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[LA-POSTE %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[LA-POSTE] 响应解析失败');
        }

        $this->throwForApiError($result, $trackingNo);

        $shipment = $result['shipment'] ?? null;
        $events = [];
        if (is_array($shipment) && is_array($shipment['event'] ?? null)) {
            foreach ($shipment['event'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $events[] = $this->mapEvent($row);
            }
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'la-poste',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['code'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('la-poste createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('la-poste createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('la-poste subscribe 待实现');
    }

    /** returnCode 非 200/207 视为业务错误；404 视为轨迹不存在 */
    private function throwForApiError(array $result, string $trackingNo): void
    {
        $returnCode = (int) ($result['returnCode'] ?? 200);
        if ($returnCode === 404) {
            throw new TrackingNotFoundException($trackingNo);
        }
        if (!in_array($returnCode, [200, 207], true)) {
            $message = (string) ($result['returnMessage'] ?? $result['message'] ?? '');
            throw new LogisticsException(sprintf('[LA-POSTE %s] %s', $returnCode, $message));
        }
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date'] ?? null),
            location: '',
            description: (string) ($row['label'] ?? ''),
            status: $this->mapStatus((string) ($row['code'] ?? ''), (string) ($row['label'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $code, string $label): TrackStatus
    {
        if (isset(self::CODE_MAP[$code])) {
            return self::CODE_MAP[$code];
        }

        $text = strtolower($label);

        if (str_contains($text, 'en cours de livraison') || str_contains($text, 'en cours de distribution')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'livré') || str_contains($text, 'distribué')
            || str_contains($text, 'livraison effectuée') || str_contains($text, 'remis')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'retour') || str_contains($text, 'refus')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'instance') || str_contains($text, 'anomalie')
            || str_contains($text, 'bloqué') || str_contains($text, 'disponible en')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'préparation') || str_contains($text, 'expéditeur')) {
            return TrackStatus::PENDING;
        }
        if (str_contains($text, 'acheminement') || str_contains($text, 'transit')
            || str_contains($text, 'en charge') || str_contains($text, 'tri')) {
            return TrackStatus::IN_TRANSIT;
        }

        return TrackStatus::UNKNOWN;
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
