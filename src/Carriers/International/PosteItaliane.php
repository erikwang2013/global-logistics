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
 * 意大利邮政（poste-italiane）适配器：国际件查询（DoveQuando DQ-REST 批量查询，公开接口无鉴权，JSON 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（ricercamultipla 请求体、
 * listaMovimenti 事件结构均为公开文档与既有集成推断；端点可用配置覆盖）。
 * 文档: https://www.poste.it/online/dovequando/DQ-REST/ricercamultipla
 */
final class PosteItaliane implements CarrierInterface
{
    private const ENDPOINT = 'https://www.poste.it/online/dovequando/DQ-REST/ricercamultipla';

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', (string) $this->config->get('poste-italiane.endpoint', self::ENDPOINT), [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Accept' => 'application/json',
            'Referer' => 'https://www.poste.it/cerca/index.html',
            'Origin' => 'https://www.poste.it',
        ], (string) json_encode([
            'tipoRichiedente' => 'WEB',
            'listaCodici' => [$trackingNo],
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[POSTE-ITALIANE %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[POSTE-ITALIANE %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[POSTE-ITALIANE] 响应解析失败');
        }

        $shipment = $this->findShipment($result, $trackingNo);
        if ($shipment === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $error = (string) ($shipment['descrizioneErrore'] ?? '');
        if ($error !== '') {
            $lower = strtolower($error);
            if (str_contains($lower, 'trovat') || str_contains($lower, 'inesist') || str_contains($lower, 'non presente')) {
                throw new TrackingNotFoundException($trackingNo);
            }
            throw new LogisticsException(sprintf('[POSTE-ITALIANE] %s', $error));
        }

        $events = [];
        foreach ($shipment['listaMovimenti'] ?? [] as $row) {
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
            carrierCode: 'poste-italiane',
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
        throw new LogisticsException('poste-italiane createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('poste-italiane createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('poste-italiane subscribe 待实现');
    }

    /** 在响应数组中定位匹配单号的托运记录；未匹配时取首条 */
    private function findShipment(array $result, string $trackingNo): ?array
    {
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['idTracciatura'] ?? ''), $trackingNo) === 0) {
                return $row;
            }
        }

        return is_array($result[0] ?? null) ? $result[0] : null;
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dataOra'] ?? null),
            location: (string) ($row['localita'] ?? $row['ufficioPostale'] ?? ''),
            description: (string) ($row['statoLavorazione'] ?? ''),
            status: $this->mapStatus((string) ($row['statoLavorazione'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = strtolower($description);

        if (str_contains($text, 'consegnat')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'in consegna') || str_contains($text, 'in viaggio verso il destinatario')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'restituit') || str_contains($text, 'ritorno') || str_contains($text, 'reso')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'giacen') || str_contains($text, 'fermo')
            || str_contains($text, 'mancata consegna') || str_contains($text, 'non consegnat')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'accettat') || str_contains($text, 'spedito')
            || str_contains($text, 'ritirato') || str_contains($text, 'presa in carico')) {
            return TrackStatus::PENDING;
        }
        if (str_contains($text, 'transit') || str_contains($text, 'lavorazione')
            || str_contains($text, 'partito') || str_contains($text, 'arrivato')
            || str_contains($text, 'smistamento')) {
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
