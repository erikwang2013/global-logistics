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
 * 巴西邮政（correios）适配器：国际件查询（Correios API Rastro，先取 token 再查对象，JSON 响应）。
 *
 * VERIFIED-REQUIRED: 契约基于公开文档模式，需实网验证（token 响应字段 token/expiraEm、
 * srorastro 响应 objetos/eventos 结构与 SRO 事件代码均为公开文档推断）。
 * 文档: https://www.correios.com.br/atendimento/developers
 */
final class Correios implements CarrierInterface
{
    private const TOKEN_URL = 'https://api.correios.com.br/token/v1/autentica';

    private const ENDPOINT = 'https://api.correios.com.br/srorastro/v1/objetos/{trackingNo}';

    /** SRO 事件代码 => 统一状态（PO/RO/OEC/BDE/BDR 为公开文档定义） */
    private const CODE_MAP = [
        'PO' => TrackStatus::PENDING, // Objeto postado
        'RO' => TrackStatus::IN_TRANSIT, // Objeto em trânsito
        'OEC' => TrackStatus::OUT_FOR_DELIVERY, // Objeto em rota de entrega
        'BDE' => TrackStatus::DELIVERED, // Objeto entregue ao destinatário
        'BDR' => TrackStatus::RETURNED, // Objeto devolvido ao remetente
    ];

    private ?string $accessToken = null;

    private ?int $expiresAt = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', str_replace('{trackingNo}', urlencode($trackingNo), self::ENDPOINT) . '?' . http_build_query([
            'resultado' => 'T',
        ]), [
            'Authorization' => 'Bearer ' . $this->token(),
            'Accept' => 'application/json',
        ]);

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREIOS %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREIOS %s] 接口错误', $status));
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[CORREIOS] 响应解析失败');
        }

        $this->throwForApiError($result);

        $objeto = null;
        foreach ($result['objetos'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['codObjeto'] ?? ''), $trackingNo) === 0) {
                $objeto = $row;
                break;
            }
        }
        if ($objeto === null) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($objeto['eventos'] ?? [] as $row) {
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
            carrierCode: 'correios',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($latest->raw['codigo'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('correios createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('correios createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('correios subscribe 待实现');
    }

    /** 获取并缓存 API token（POST /token/v1/autentica，HTTP Basic；有效期为 expiraEm，预留 60s 缓冲） */
    private function token(): string
    {
        if ($this->accessToken !== null && ($this->expiresAt === null || $this->expiresAt > time())) {
            return $this->accessToken;
        }

        $request = new \GuzzleHttp\Psr7\Request('POST', self::TOKEN_URL, [
            'Authorization' => 'Basic ' . base64_encode(
                (string) $this->config->get('correios.user') . ':' . (string) $this->config->get('correios.password'),
            ),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], '{}');

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[CORREIOS %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[CORREIOS %s] token 获取失败', $status));
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || !isset($body['token']) || !is_string($body['token']) || $body['token'] === '') {
            throw new AuthException('[CORREIOS] token 获取失败：响应中缺少 token');
        }

        $this->accessToken = $body['token'];
        $this->expiresAt = null;
        if (isset($body['expiraEm']) && is_string($body['expiraEm'])) {
            foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat($format, $body['expiraEm']);
                if ($dt !== false) {
                    $this->expiresAt = $dt->getTimestamp() - 60;
                    break;
                }
            }
        }

        return $this->accessToken;
    }

    private function throwForApiError(array $result): void
    {
        $erros = $result['erros'] ?? null;
        if (!is_array($erros) || $erros === []) {
            return;
        }

        $messages = [];
        foreach ($erros as $erro) {
            if (is_array($erro) && isset($erro['descricao'])) {
                $messages[] = (string) $erro['descricao'];
            }
        }

        throw new LogisticsException('[CORREIOS] ' . implode('; ', $messages));
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $location = '';
        $unidade = $row['unidade'] ?? null;
        if (is_array($unidade)) {
            $endereco = $unidade['endereco'] ?? null;
            if (is_array($endereco)) {
                $city = (string) ($endereco['cidade'] ?? '');
                $uf = (string) ($endereco['uf'] ?? '');
                $location = trim($city . ($uf !== '' ? ' - ' . $uf : ''));
            }
            if ($location === '') {
                $location = (string) ($unidade['tipo'] ?? '');
            }
        }

        return new TrackingEvent(
            occurredAt: $this->parseTime($row['dtHrCriado'] ?? null),
            location: $location,
            description: (string) ($row['descricao'] ?? ''),
            status: $this->mapStatus((string) ($row['codigo'] ?? ''), (string) ($row['descricao'] ?? '')),
            raw: $row,
        );
    }

    private function mapStatus(string $code, string $description): TrackStatus
    {
        if (isset(self::CODE_MAP[$code])) {
            return self::CODE_MAP[$code];
        }

        $text = strtolower($description);

        if (str_contains($text, 'devolv')) {
            return TrackStatus::RETURNED;
        }
        if (str_contains($text, 'entregue')) {
            return TrackStatus::DELIVERED;
        }
        if (str_contains($text, 'rota de entrega') || str_contains($text, 'saiu para entrega')
            || str_contains($text, 'em entrega')) {
            return TrackStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($text, 'insufici') || str_contains($text, 'pendênc')
            || str_contains($text, 'bloquead') || str_contains($text, 'não localizad')
            || str_contains($text, 'avariad') || str_contains($text, 'não entregue')) {
            return TrackStatus::EXCEPTION;
        }
        if (str_contains($text, 'postad') || str_contains($text, 'coletad')
            || str_contains($text, 'aceit') || str_contains($text, 'preparado')) {
            return TrackStatus::PENDING;
        }
        if (str_contains($text, 'trânsit') || str_contains($text, 'encaminhad')
            || str_contains($text, 'chegou') || str_contains($text, 'recebid')
            || str_contains($text, 'transport')) {
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
