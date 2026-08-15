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
 * 顺丰国际（SF International，含国际小包/电商专递/国际标快）适配器。
 *
 * 与国内顺丰同源（丰桥平台签名协议），但使用国际件专用路由查询服务
 * EXP_RECE_SEARCH_ROUTES 且单号为 SF+13 位（15 位新标准）。
 * VERIFIED-REQUIRED: 契约基于丰桥公开文档（国际件路由查询接口），
 * msgData 路由字段名（routeResps[].routes）需开通国际接口后实网验证。
 * 文档: https://qiao.sf-express.com/doc/download/SF-CSIM-API.pdf
 */
final class SfInternational implements CarrierInterface
{
    private const ENDPOINT = 'https://sfapi.sf-express.com/std/service';

    /** opcode => 统一状态（顺丰国际路由 opcode 语义，与丰桥国内版一致） */
    private const STATUS_MAP = [
        '50' => TrackStatus::PENDING,
        '60' => TrackStatus::IN_TRANSIT,
        '70' => TrackStatus::OUT_FOR_DELIVERY,
        '8000' => TrackStatus::DELIVERED,
        '9010' => TrackStatus::EXCEPTION,
        '9000' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $msgData = [
            'language' => '0',
            'trackingType' => '1',
            'trackingNumber' => $trackingNo,
            'methodType' => '1',
            'checkPhoneNo' => $options['phone'] ?? '',
        ];

        $response = $this->post('EXP_RECE_SEARCH_ROUTES', $msgData);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[SF-INTERNATIONAL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[SF-INTERNATIONAL %s] 接口错误', $status));
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new LogisticsException('[SF-INTERNATIONAL] 响应解析失败');
        }
        if (($data['success'] ?? false) !== true) {
            $this->throwForApiError($data);
        }

        $routeResps = $data['msgData']['routeResps'] ?? [];
        $rawEvents = is_array($routeResps) && $routeResps !== [] ? ($routeResps[0]['routes'] ?? []) : [];
        if (!is_array($rawEvents) || $rawEvents === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 路由轨迹按时间升序返回，末条为最新；若返回降序则反转
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
        $first = $events[0]->occurredAt;
        $last = $events[count($events) - 1]->occurredAt;
        if ($first !== null && $last !== null && $first > $last) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'sf-international',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['opcode'] ?? ''),
            raw: $data,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('sf-international createOrder 待实现（需顺丰国际下单接口开通）');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('sf-international createLabel 待实现（需顺丰国际面单接口开通）');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('sf-international subscribe 待实现（需顺丰国际路由推送接口开通）');
    }

    /** @param array<string, mixed> $msgData */
    private function post(string $serviceCode, array $msgData): \Psr\Http\Message\ResponseInterface
    {
        $json = json_encode($msgData, JSON_UNESCAPED_UNICODE);
        $body = json_encode([
            'partnerID' => $this->config->get('sf-international.partner_id'),
            'requestID' => bin2hex(random_bytes(8)),
            'serviceCode' => $serviceCode,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
            'msgData' => $json,
            'msgDigest' => base64_encode(md5($json . $this->config->get('sf-international.checkword', ''), true)),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', (string) $this->config->get('sf-international.endpoint', self::ENDPOINT), [
            'Content-Type' => 'application/json',
        ], $body);

        return $this->http->sendRequest($request);
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['acceptTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['acceptAddress'] ?? ''),
            description: (string) ($row['remark'] ?? ''),
            status: self::STATUS_MAP[(string) ($row['opcode'] ?? '')] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }

    /** @param array<string, mixed> $data */
    private function throwForApiError(array $data): never
    {
        $code = (string) ($data['apiResultCode'] ?? '');
        $message = (string) ($data['apiErrorMsg'] ?? '请求失败');

        if ($code === '8000' || $code === '8001' || $code === '8002') {
            throw new AuthException(sprintf('[SF-INTERNATIONAL %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[SF-INTERNATIONAL %s] %s', $code, $message));
    }
}
