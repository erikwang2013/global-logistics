<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\NetworkException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OAuthTokenClientTest extends TestCase
{
    public function testFetchesTokenLazilyOnFirstRequestAndCaches(): void
    {
        $tokenCalls = 0;
        $trackedCalls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$tokenCalls, &$trackedCalls) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                $tokenCalls++;
                $this->assertSame('POST', $request->getMethod());
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-1","expires_in":3600}');
            }

            $trackedCalls++;
            $this->assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
            return new Response(200, [], 'ok');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));

        $this->assertSame(1, $tokenCalls);
        $this->assertSame(2, $trackedCalls);
    }

    public function testSendsCredentialsInFormBody(): void
    {
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) {
            if ($request->getUri()->getPath() !== '/oauth/token') {
                return new Response(200, [], 'ok');
            }

            $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            $this->assertSame(
                'grant_type=client_credentials&client_id=cid&client_secret=cs',
                (string) $request->getBody(),
            );
            $this->assertSame('', $request->getHeaderLine('Authorization'));
            return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-1","expires_in":3600}');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
    }

    public function testSendsBasicAuthHeaderWhenEnabled(): void
    {
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) {
            if ($request->getUri()->getPath() !== '/oauth/token') {
                return new Response(200, [], 'ok');
            }

            $this->assertSame('Basic ' . base64_encode('cid:cs'), $request->getHeaderLine('Authorization'));
            $body = (string) $request->getBody();
            $this->assertStringContainsString('grant_type', $body);
            $this->assertStringNotContainsString('client_id', $body);
            $this->assertStringNotContainsString('client_secret', $body);
            return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-1","expires_in":3600}');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
            basicAuth: true,
        );

        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
    }

    public function testRefreshesTokenOn401AndRetriesOnce(): void
    {
        $tokenCalls = 0;
        $trackedCalls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$tokenCalls, &$trackedCalls) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                $tokenCalls++;
                $token = $tokenCalls === 1 ? 'tok-1' : 'tok-2';
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"' . $token . '","expires_in":3600}');
            }

            $trackedCalls++;
            if ($trackedCalls === 1) {
                $this->assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
                return new Response(401, [], 'unauthorized');
            }

            $this->assertSame('Bearer tok-2', $request->getHeaderLine('Authorization'));
            return new Response(200, [], 'ok');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        $response = $client->sendRequest(new Request('GET', 'https://api.example.com/track'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $tokenCalls);
        $this->assertSame(2, $trackedCalls);
    }

    public function testThrowsAuthExceptionWhenTokenEndpointFails(): void
    {
        $inner = new FakeHttpClient();
        $inner->handler = fn () => new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}');

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        try {
            $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
            $this->fail('Expected AuthException to be thrown');
        } catch (AuthException $e) {
            $this->assertSame('OAuth token 获取失败：{"error":"invalid_client"}', $e->getMessage());
        }
    }

    public function testRefetchesWhenTokenExpired(): void
    {
        $tokenCalls = 0;
        $trackedCalls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$tokenCalls, &$trackedCalls) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                $tokenCalls++;
                $token = $tokenCalls === 1 ? 'tok-1' : 'tok-2';
                $expiresIn = $tokenCalls === 1 ? 0 : 3600;
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"' . $token . '","expires_in":' . $expiresIn . '}');
            }

            $trackedCalls++;
            $this->assertSame('Bearer ' . ($trackedCalls === 1 ? 'tok-1' : 'tok-2'), $request->getHeaderLine('Authorization'));
            return new Response(200, [], 'ok');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
        $client->sendRequest(new Request('GET', 'https://api.example.com/track'));

        $this->assertSame(2, $tokenCalls);
        $this->assertSame(2, $trackedCalls);
    }

    public function testSecond401Propagates(): void
    {
        $tokenCalls = 0;
        $trackedCalls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$tokenCalls, &$trackedCalls) {
            if ($request->getUri()->getPath() === '/oauth/token') {
                $tokenCalls++;
                $token = $tokenCalls === 1 ? 'tok-1' : 'tok-2';
                return new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"' . $token . '","expires_in":3600}');
            }

            $trackedCalls++;
            $this->assertSame('Bearer ' . ($trackedCalls === 1 ? 'tok-1' : 'tok-2'), $request->getHeaderLine('Authorization'));
            return new Response(401, [], 'unauthorized');
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        $response = $client->sendRequest(new Request('GET', 'https://api.example.com/track'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(2, $tokenCalls);
        $this->assertSame(2, $trackedCalls);
    }

    public function testThrowsNetworkExceptionOnTransportFailure(): void
    {
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) {
            throw new \GuzzleHttp\Exception\ConnectException('boom', $request);
        };

        $client = new OAuthTokenClient(
            $inner,
            'https://api.example.com/oauth/token',
            ['client_id' => 'cid', 'client_secret' => 'cs'],
        );

        try {
            $client->sendRequest(new Request('GET', 'https://api.example.com/track'));
            $this->fail('Expected NetworkException to be thrown');
        } catch (NetworkException $e) {
            $this->assertStringStartsWith('OAuth token 获取失败：', $e->getMessage());
            $this->assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $e->getPrevious());
        }
    }
}
