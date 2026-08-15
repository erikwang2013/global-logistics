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
 * BH Pošta（波黑邮政）国际件查询（官方 Track & Trace 页面，GET + barcode 参数，
 * 无认证；公开 JSON API 未开放，故按页面表格解析：Date | Postal Unit |
 * [City] | Description）。
 *
 * VERIFIED-REQUIRED: 契约基于公开网页资料（官方 Track & Trace 页面
 * https://bhpwebout.posta.ba/trackwebapp/），需实网验证（查询参数名与表格
 * 列数以官网实际输出为准；日期为 d.m.Y 无时间部分，本适配器按当日零点处理；
 * 单号无效时页面无数据行，按无事件处理；状态关键词为 BH Pošta 官网波斯尼亚语
 * 标准描述）。
 * 文档: https://bhpwebout.posta.ba/trackwebapp/
 */
final class BosniaPost implements CarrierInterface
{
    private const ENDPOINT = 'https://bhpwebout.posta.ba/trackwebapp/';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含波斯尼亚语）。
     * 'u dostavi'（派送中）须先于 'uručena'（已送达）。
     */
    private const STATUS_MAP = [
        'u dostavi|for delivery|out for delivery' => TrackStatus::OUT_FOR_DELIVERY,
        'uručena|urucena|isporučena|delivered' => TrackStatus::DELIVERED,
        'povrat|vraćena|vracena|return' => TrackStatus::RETURNED,
        'neuručena|neisporučena|failed|held|unclaimed|undeliver|exception' => TrackStatus::EXCEPTION,
        'primljena|prihvaćena|accepted|received|collected|picked up|ready for pickup' => TrackStatus::PENDING,
        'u tranzitu|in transit|transit|tranzit|pristigla|poslana|arrived|departed|sorted|dispatched|forwarded|processed|registered' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('bosnia-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'barcode' => $trackingNo,
        ]), [
            'Accept' => 'text/html, application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[BOSNIA-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[BOSNIA-POST %s] 接口错误', $status));
        }

        $html = (string) $response->getBody();
        // 响应若为 JSON（如网关错误包装）且非对象/数组，按解析失败处理
        $decoded = json_decode($html, true);
        if ($decoded !== null && !is_array($decoded)) {
            throw new LogisticsException('[BOSNIA-POST] 响应解析失败');
        }

        $events = [];
        foreach ($this->extractRows($html) as $row) {
            if ($row === []) {
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
            carrierCode: 'bosnia-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['description'] ?? '',
            raw: ['html' => $html],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('bosnia-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('bosnia-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('bosnia-post subscribe 待实现');
    }

    /**
     * 从跟踪页 HTML 中提取表格行（Date | Postal Unit | [City] | Description，
     * 跳过表头行；容错：未匹配到 4 列时尝试 3 列 Date | Location | Description）。
     *
     * @return array<int, array{date: string, unit: string, city: string, description: string}>
     */
    private function extractRows(string $html): array
    {
        $rows = [];
        if (!preg_match_all('~<tr[^>]*>(.*?)</tr>~si', $html, $rowMatches, PREG_SET_ORDER)) {
            return $rows;
        }
        foreach ($rowMatches as $rowMatch) {
            if (!preg_match_all('~<td[^>]*>(.*?)</td>~si', $rowMatch[1], $cellMatches, PREG_SET_ORDER) || count($cellMatches) < 3) {
                continue;
            }
            $cells = array_map(static fn (array $c): string => trim(strip_tags($c[1])), $cellMatches);
            $date = $cells[0];
            $dateLower = function_exists('mb_strtolower') ? mb_strtolower($date, 'UTF-8') : strtolower($date);
            if (in_array($dateLower, ['date', 'datum', 'data', 'датум', 'дата', 'ημερομηνία'], true)) {
                continue; // 表头行
            }
            $rows[] = isset($cells[3])
                ? ['date' => $date, 'unit' => $cells[1], 'city' => $cells[2], 'description' => $cells[3]]
                : ['date' => $date, 'unit' => $cells[1], 'city' => '', 'description' => $cells[2]];
        }

        return $rows;
    }

    /**
     * @param array{date: string, unit: string, city: string, description: string} $row
     */
    private function mapEvent(array $row): TrackingEvent
    {
        $location = trim($row['unit'] . ($row['city'] !== '' ? ', ' . $row['city'] : ''));

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['date']),
            location: $location,
            description: $row['description'],
            status: $this->mapStatus($row['description']),
            raw: $row,
        );
    }

    private function mapStatus(string $description): TrackStatus
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($description, 'UTF-8') : strtolower($description);
        foreach (self::STATUS_MAP as $keywords => $status) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $status;
                }
            }
        }

        return TrackStatus::UNKNOWN;
    }

    /** 支持 'd.m.Y'（官网格式，无时间部分）、'd/m/Y'、ISO8601 等，解析失败返回 null */
    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        $raw = is_string($value) ? $value : '';
        if ($raw === '') {
            return null;
        }

        // 兼容 6 位微秒小数秒截断为 3 位毫秒（仅限 ISO8601 时间戳，避免误伤 d.m.Y 日期）
        if (str_contains($raw, 'T') && preg_match('/^(.*\.)(\d{4,})(.*)$/', $raw, $m)) {
            $raw = $m[1] . substr($m[2], 0, 3) . $m[3];
        }

        foreach ([
            'd.m.Y H:i:s', 'd.m.Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i',
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d.m.Y', '!d/m/Y', '!Y-m-d',
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
