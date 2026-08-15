<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\NetworkException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client that transparently fetches and refreshes an OAuth2 client-credentials token.
 *
 * Credentials keys: `client_id`, `client_secret`. When `$basicAuth` is true the credentials
 * are sent in the Basic Authorization header and the body carries only `grant_type`
 * (RFC 6749 §2.3 permits exactly one authentication method per request); when false the
 * credentials go in the form body and no Authorization header is set. A missing `expires_in`
 * means the token never expires and is cached until a 401 forces a refresh.
 */
final class OAuthTokenClient implements ClientInterface
{
    private ?string $accessToken = null;

    private ?int $expiresAt = null;

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $tokenUrl,
        private readonly array $credentials,
        private readonly bool $basicAuth = false,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('Authorization', 'Bearer ' . $this->token());

        $response = $this->inner->sendRequest($request);

        if ($response->getStatusCode() === 401) {
            $this->accessToken = null;
            $this->expiresAt = null;

            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token());
            $response = $this->inner->sendRequest($request);
        }

        return $response;
    }

    private function token(): string
    {
        if ($this->accessToken !== null && ($this->expiresAt === null || $this->expiresAt > time())) {
            return $this->accessToken;
        }

        $request = $this->buildTokenRequest();

        try {
            $response = $this->inner->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new NetworkException(
                'OAuth token 获取失败：' . $e->getMessage(),
                request: $request,
                previous: $e,
            );
        }

        $raw = (string) $response->getBody();

        if ($response->getStatusCode() !== 200) {
            throw new AuthException(sprintf(
                'OAuth token 获取失败（HTTP %d）：%s',
                $response->getStatusCode(),
                $this->sanitizeErrorBody($raw),
            ));
        }

        $body = json_decode($raw, true);
        if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token'])) {
            throw new AuthException(sprintf(
                'OAuth token 获取失败（HTTP %d）：%s',
                $response->getStatusCode(),
                $this->sanitizeErrorBody($raw),
            ));
        }

        $this->accessToken = $body['access_token'];
        $this->expiresAt = isset($body['expires_in']) && is_numeric($body['expires_in'])
            // 预留 60s 时钟偏移缓冲
            ? time() + (int) $body['expires_in'] - 60
            : null;

        return $this->accessToken;
    }

    /**
     * 截断并脱敏错误响应体，避免响应回显的凭据（client_secret/access_token 等）泄露进日志。
     */
    private function sanitizeErrorBody(string $raw): string
    {
        $truncated = preg_match('/^.{0,200}/us', $raw, $m) === 1 ? $m[0] : $raw;
        $display = strlen($raw) > strlen($truncated) ? $truncated . '...' : $truncated;

        return preg_replace(
            '/("?(?:client_secret|access_token|refresh_token|token)"?\s*[:=]\s*"[^"]*")/i',
            '***',
            $display,
        ) ?? $display;
    }

    private function buildTokenRequest(): RequestInterface
    {
        $clientId = (string) ($this->credentials['client_id'] ?? '');
        $clientSecret = (string) ($this->credentials['client_secret'] ?? '');

        $body = ['grant_type' => 'client_credentials'];

        if ($this->basicAuth) {
            // RFC 6749 §2.3: credentials go in the Basic header only, not in the body
            $request = new \GuzzleHttp\Psr7\Request(
                'POST',
                $this->tokenUrl,
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
                http_build_query($body),
            );
        } else {
            $body['client_id'] = $clientId;
            $body['client_secret'] = $clientSecret;

            $request = new \GuzzleHttp\Psr7\Request(
                'POST',
                $this->tokenUrl,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query($body),
            );
        }

        return $request;
    }
}
