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
 * Turkey PTT（土耳其邮政）国际件查询（官方 gonderitakip.ptt.gov.tr 公共查询端点，
 * GET + q 参数，无认证）。
 *
 * VERIFIED-REQUIRED: 契约基于 PTT 官网 gonderitakip.ptt.gov.tr 公共查询接口
 * （https://gonderitakip.ptt.gov.tr/Track/Verify?q={barcode}，kargo-takip-turkiye /
 * KargoTakipURL 等开源项目均使用该端点）；该站点为 JSF 渲染，响应体实际为 HTML，
 * 本适配器按公开逆向资料推断为 JSON（Status 布尔 + Data 内含 Events 事件数组，
 * 字段名 EventDate/EventDescription/EventLocation），需实网验证；单号无效时
 * Status=false 或 Data 为空，按无事件处理；状态关键词为 PTT 官网标准
 * （Kabul Edildi/Teslim Edildi/Dağıtımda 等）。
 * 文档: https://gonderitakip.ptt.gov.tr/
 */
final class TurkeyPost implements CarrierInterface
{
    private const ENDPOINT = 'https://gonderitakip.ptt.gov.tr/Track/Verify';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含土耳其语）。
     * 'dağıtımda'（派送中）须先于 'teslim'（"teslim edildi" 已交付）。
     */
    private const STATUS_MAP = [
        'out for delivery|dağıtımda|distributing' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|teslim edildi|ulaştırıldı' => TrackStatus::DELIVERED,
        'returned|iade|geri gönderildi' => TrackStatus::RETURNED,
        'failed|exception|held|damaged|hasarlı|reddedildi|ulaşılamadı|beklemede' => TrackStatus::EXCEPTION,
        'accepted|received|created|kabul edildi|alındı|hazırlanıyor|kayıt oluşturuldu' => TrackStatus::PENDING,
        'in transit|transit|transferde|aktarma|şubede|yola çıktı|depart|arrived|sorted|dispatched|shipped' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('turkey-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'q' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[TURKEY-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[TURKEY-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[TURKEY-POST] 响应解析失败');
        }

        $payload = $result['Data'] ?? $result['data'] ?? null;
        if (!is_array($payload)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $rawEvents = $payload['Events'] ?? $payload['Movements'] ?? null;
        if (!is_array($rawEvents)) {
            $rawEvents = $payload;
        }
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
        // 事件按时间升序返回，末条为最新；若返回降序则反转
        $events = $this->ensureAscending($events);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'turkey-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($payload['StatusText'] ?? $result['StatusText'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('turkey-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('turkey-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('turkey-post subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $description = (string) ($row['EventDescription'] ?? $row['Description'] ?? $row['Islem'] ?? $row['Aciklama'] ?? '');

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['EventDate'] ?? $row['Date'] ?? $row['Tarih'] ?? null),
            location: (string) ($row['EventLocation'] ?? $row['Location'] ?? $row['Sube'] ?? ''),
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

        return TrackStatus::UNKNOWN;
    }

    /** 支持 ISO8601、'Y-m-d H:i:s'、'd/m/Y H:i:s' 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒
        if (preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', '!Y-m-d', '!d/m/Y', '!d-m-Y',
        ] as $format) {
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
