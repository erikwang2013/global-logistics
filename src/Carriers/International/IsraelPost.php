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
 * Israel Post（以色列邮政）国际件查询（itemtrace JSON 端点，GET + itemcode 参数，
 * 无认证；响应 itemcodeinfo 为内含跟踪表格的 HTML 字符串，按 4 列
 * Date | Postal Unit | City | Description 解析）。
 *
 * VERIFIED-REQUIRED: 契约基于公开逆向资料（Stack Overflow #45132267，
 * trackandtraceNOHEJSON 端点 + Gson 解析 itemcodeinfo 为 HTML 表格行：
 * 日期 d/m/Y、Postal Unit、City、Description），需实网验证（端点历年来保持稳定，
 * 表格列数与顺序以官网实际输出为准；日期无时间部分，本适配器按当日零点处理；
 * 单号无效时 itemcodeinfo 为空字符串或表格无数据行，按无事件处理；状态关键词为
 * Israel Post 官网英文/希伯来语标准描述）。
 * 文档: https://www.israelpost.co.il/
 */
final class IsraelPost implements CarrierInterface
{
    private const ENDPOINT = 'https://www.israelpost.co.il/itemtrace.nsf/trackandtraceNOHEJSON';

    /**
     * 事件描述关键词 => 统一状态（小写匹配，`|` 分隔同义关键词，含希伯来语）。
     * 'for delivery'（派送中）须先于 'delivered'。
     */
    private const STATUS_MAP = [
        'out for delivery|for delivery|delivery to addressee|לקראת מסירה' => TrackStatus::OUT_FOR_DELIVERY,
        'delivered|נמסר|הועבר לנמען' => TrackStatus::DELIVERED,
        'returned|return to sender|החזרה' => TrackStatus::RETURNED,
        'undeliver|failed|held|refused|unclaimed|exception' => TrackStatus::EXCEPTION,
        'received for mailing|accepted|created|collected|picked up|ready for pickup|נמסר לדואר' => TrackStatus::PENDING,
        'in transit|transit|processed|arrived|departed|sorted|dispatched|forwarded|transferred|on its way|בדרך|התקבל' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('israel-post.endpoint', self::ENDPOINT);
        $request = new \GuzzleHttp\Psr7\Request('GET', $endpoint . '?' . http_build_query([
            'openagent' => '',
            'lang' => 'EN',
            'itemcode' => $trackingNo,
        ]), [
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[ISRAEL-POST %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[ISRAEL-POST %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[ISRAEL-POST] 响应解析失败');
        }

        $info = $result['itemcodeinfo'] ?? $result['data']['itemcodeinfo'] ?? null;
        if (!is_string($info) || $info === '') {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($this->extractRows($info) as $row) {
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
            carrierCode: 'israel-post',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->raw['description'] ?? '',
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('israel-post createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('israel-post createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('israel-post subscribe 待实现');
    }

    /**
     * 从 itemcodeinfo HTML 中提取表格行（Date | Postal Unit | City | Description，
     * 跳过表头行；容错：未匹配到 4 列时尝试 3 列 Date | Location | Description）。
     *
     * @return array<int, array{date: string, unit: string, city: string, description: string}>
     */
    private function extractRows(string $html): array
    {
        $rows = [];
        $patterns = [
            '~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~si',
            '~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>~si',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $date = trim(strip_tags($m[1]));
                    if (strtolower($date) === 'date') {
                        continue; // 表头行
                    }
                    $unit = trim(strip_tags($m[2]));
                    if (isset($m[4])) {
                        $rows[] = [
                            'date' => $date,
                            'unit' => $unit,
                            'city' => trim(strip_tags($m[3])),
                            'description' => trim(strip_tags($m[4])),
                        ];
                    } else {
                        $rows[] = [
                            'date' => $date,
                            'unit' => '',
                            'city' => '',
                            'description' => trim(strip_tags($m[3])),
                        ];
                    }
                }
                break;
            }
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

    /** 支持 'd/m/Y'（官网格式，无时间部分）、ISO8601、'Y-m-d H:i:s' 等，解析失败返回 null */
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
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y g:i:s A', 'd-m-Y H:i:s', 'd-m-Y H:i',
            'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i',
            // 纯日期格式需 `!` 重置未指定时间部分，否则 PHP 8.2+ 会填入当前时刻
            '!d/m/Y', '!d-m-Y', '!Y-m-d',
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
