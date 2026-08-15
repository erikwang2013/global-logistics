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

/**
 * 日日顺（Rrs）适配器。
 *
 * 契约基于日日顺供应链开放平台实时查询接口 RRS_REALTIME（被动模式）：
 * https://www.rrswl.com/front/wmdt/api/api2New?apiid=8000008
 * 表单字段 sign=base64(md5(content+keyValue))，content 为 <Code><expno>…</expno></Code> 报文，
 * 响应为 <request><flag>T|F</flag><response><Realtime>…</Realtime></response><msg>…</msg></request>。
 *
 * VERIFIED-REQUIRED: 文档公开的生产地址为内网 IP（58.56.128.10:19001，INT_CODE=EAI_INT_1353），
 * 线上可达地址、notifyid/source 取值与 keyValue 分发方式需实网验证。
 */
final class Rrs implements CarrierInterface
{
    private const ENDPOINT = 'http://58.56.128.10:19001/EAI/RoutingProxyService/EAI_REST_POST_ServiceRoot?INT_CODE=EAI_INT_1353';

    /** 日日顺状态关键词 => 统一状态（以 nodemes 内容匹配，具体在前） */
    private const STATUS_MAP = [
        '异常|疑难|滞留|未签收|拒收' => TrackStatus::EXCEPTION,
        '签收' => TrackStatus::DELIVERED,
        '派送|派件|配送' => TrackStatus::OUT_FOR_DELIVERY,
        '退回|退件' => TrackStatus::RETURNED,
        '揽收|收件|接单' => TrackStatus::PENDING,
        '到达|出发|运输|中转|分拨|在途' => TrackStatus::IN_TRANSIT,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $endpoint = (string) $this->config->get('rrs.endpoint', self::ENDPOINT);
        $keyValue = (string) $this->config->get('rrs.key_value');
        $source = (string) $this->config->get('rrs.source', '');

        $content = '<Code><expno>' . $trackingNo . '</expno></Code>';
        $sign = base64_encode(md5($content . $keyValue, true));

        $body = http_build_query([
            'notifyid' => (string) $this->config->get('rrs.notifyid', uniqid('rrs', true)),
            'notifytime' => date('Y-m-d H:i:s'),
            'butype' => 'rrs_statusback',
            'source' => $source,
            'type' => 'xml',
            'sign' => $sign,
            'content' => $content, // http_build_query 做一次 URL 编码
        ]);

        $request = new \GuzzleHttp\Psr7\Request('POST', $endpoint, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);

        $response = $this->http->sendRequest($request);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthException(sprintf('[RRS %d] 认证失败', $statusCode));
        }
        if ($statusCode >= 400) {
            throw new LogisticsException(sprintf('[RRS %d] 接口错误', $statusCode));
        }

        $xml = @simplexml_load_string((string) $response->getBody());
        if ($xml === false) {
            throw new LogisticsException('[RRS] 响应解析失败');
        }

        if ((string) $xml->flag !== 'T') {
            $this->throwForApiError((string) ($xml->msg ?? '未知错误'));
        }

        $nodes = [];
        foreach ($xml->response->Realtime as $node) {
            $nodes[] = $node;
        }
        if ($nodes === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 节点按时间升序返回，末条为最新；若返回降序则反转
        $events = [];
        foreach ($nodes as $node) {
            $events[] = $this->mapEvent($node);
        }
        if (count($events) > 1
            && $events[0]->occurredAt !== null
            && $events[count($events) - 1]->occurredAt !== null
            && $events[0]->occurredAt > $events[count($events) - 1]->occurredAt) {
            $events = array_reverse($events);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'rrs',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: trim((string) $nodes[count($nodes) - 1]->nodemes),
            raw: iterator_to_array($xml->response->Realtime),
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('RRS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('RRS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('RRS subscribe 待实现');
    }

    private function mapEvent(\SimpleXMLElement $node): TrackingEvent
    {
        $description = trim((string) $node->nodemes);
        $occurredAt = $this->parseTime(trim((string) $node->operdate) . ' ' . trim((string) $node->opertime));

        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keywords => $mapped) {
            foreach (explode('|', $keywords) as $keyword) {
                if (str_contains($description, $keyword)) {
                    $status = $mapped;
                    break 2;
                }
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: trim((string) $node->remark),
            description: $description,
            status: $status,
            raw: [
                'nodemes' => $description,
                'opername' => trim((string) $node->opername),
                'opertel' => trim((string) $node->opertel),
                'operdate' => trim((string) $node->operdate),
                'opertime' => trim((string) $node->opertime),
                'remark' => trim((string) $node->remark),
            ],
        );
    }

    private function parseTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }

    private function throwForApiError(string $message): never
    {
        foreach (['签名', '授权', 'keyValue', '权限'] as $keyword) {
            if (str_contains($message, $keyword)) {
                throw new AuthException(sprintf('[RRS] %s', $message));
            }
        }

        throw new LogisticsException(sprintf('[RRS] %s', $message));
    }
}
