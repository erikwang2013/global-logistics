<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\NetworkException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
                previous: $e,
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw new AuthException('OAuth token 获取失败：' . $response->getBody()->getContents());
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body) || !isset($body['access_token']) || !is_string($body['access_token'])) {
            throw new AuthException('OAuth token 获取失败：' . $response->getBody()->getContents());
        }

        $this->accessToken = $body['access_token'];
        $this->expiresAt = isset($body['expires_in']) && is_numeric($body['expires_in'])
            ? time() + (int) $body['expires_in'] - 60
            : null;

        return $this->accessToken;
    }

    private function buildTokenRequest(): RequestInterface
    {
        $clientId = (string) ($this->credentials['client_id'] ?? '');
        $clientSecret = (string) ($this->credentials['client_secret'] ?? '');

        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            $this->tokenUrl,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]),
        );

        if ($this->basicAuth) {
            $request = $request->withHeader('Authorization', 'Basic ' . base64_encode($clientId . ':' . $clientSecret));
        }

        return $request;
    }
}
