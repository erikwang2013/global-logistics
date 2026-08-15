<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

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

final class Sf implements CarrierInterface
{
    private const ENDPOINT = 'https://sfapi.sf-express.com/std/service';

    /** opcode => 统一状态（顺丰路由 opcode 语义，以官方文档为准） */
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
            'trackingType' => '1',
            'trackingNumber' => $trackingNo,
            'methodType' => '1',
            'checkPhoneNo' => $options['phone'] ?? '',
        ];

        $response = $this->post('EXP_RECE_SEARCH', $msgData);
        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            $this->throwForApiError(is_array($data) ? $data : []);
        }

        $routeList = $data['msgData']['waybillRouteInfoList'][0]['waybillRouteInfo'] ?? [];
        if ($routeList === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 路由轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $routeList);
        $latest = $events[count($events) - 1];
        $lastRaw = $routeList[count($routeList) - 1];

        return new Tracking(
            carrierCode: 'sf',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($lastRaw['opcode'] ?? ''),
            raw: $data,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('SF createOrder 待实现（需丰桥下单接口开通）');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('SF createLabel 待实现（需丰桥电子面单接口开通）');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        $msgData = [
            'trackingType' => '1',
            'trackingNumber' => $options['tracking_no'] ?? '',
            'callbackUrl' => $callbackUrl,
        ];
        $response = $this->post('EXP_RECE_SUBSCRIBE', $msgData);
        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            $this->throwForApiError(is_array($data) ? $data : []);
        }
    }

    public function verifyCallbackSignature(string $payload, string $digest): bool
    {
        $checkword = (string) $this->config->get('sf.checkword', '');
        if ($checkword === '') {
            // 缺失时若静默返回 false，回调会被误判为签名不符而拒收，改为显式报错
            throw new LogisticsException('SF checkword 未配置');
        }

        $expected = base64_encode(md5($payload . $checkword, true));

        return hash_equals($expected, $digest);
    }

    /** @param array<string, mixed> $msgData */
    private function post(string $serviceCode, array $msgData): \Psr\Http\Message\ResponseInterface
    {
        $json = json_encode($msgData, JSON_UNESCAPED_UNICODE);
        $body = json_encode([
            'partnerID' => $this->config->get('sf.partner_id'),
            'requestID' => bin2hex(random_bytes(8)),
            'serviceCode' => $serviceCode,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
            'msgData' => $json,
            'msgDigest' => base64_encode(md5($json . $this->config->get('sf.checkword', ''), true)),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
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
            throw new AuthException(sprintf('[SF %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[SF %s] %s', $code, $message));
    }
}
