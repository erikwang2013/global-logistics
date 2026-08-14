<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Http\RetryingClient;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RetryingClientTest extends TestCase
{
    public function testRetriesOnServerError(): void
    {
        $calls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function () use (&$calls) {
            $calls++;
            return $calls < 3 ? new Response(500) : new Response(200, [], 'ok');
        };

        $client = new RetryingClient($inner, maxRetries: 2);
        $response = $client->sendRequest(new Request('GET', 'https://example.com'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $calls);
    }

    public function testNoRetryOnClientError(): void
    {
        $calls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function () use (&$calls) {
            $calls++;
            return new Response(400);
        };

        $client = new RetryingClient($inner, maxRetries: 2);
        $client->sendRequest(new Request('GET', 'https://example.com'));

        $this->assertSame(1, $calls);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $inner = new FakeHttpClient();
        $inner->handler = fn () => new Response(500);

        $client = new RetryingClient($inner, maxRetries: 1);
        $response = $client->sendRequest(new Request('GET', 'https://example.com'));

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testRetriesOnTransportException(): void
    {
        $calls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \GuzzleHttp\Exception\ConnectException('boom', $request);
            }

            return new Response(200, [], 'ok');
        };

        $client = new RetryingClient($inner, maxRetries: 2);
        $response = $client->sendRequest(new Request('GET', 'https://example.com'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $calls);
    }

    public function testGivesUpOnPersistentTransportException(): void
    {
        $calls = 0;
        $inner = new FakeHttpClient();
        $inner->handler = function (Request $request) use (&$calls) {
            $calls++;
            throw new \GuzzleHttp\Exception\ConnectException('boom', $request);
        };

        $client = new RetryingClient($inner, maxRetries: 1);

        // try/catch instead of expectException: expectException aborts the test
        // at the throw site, so the call-count assertion would never run.
        try {
            $client->sendRequest(new Request('GET', 'https://example.com'));
            $this->fail('Expected GlobalLogistics NetworkException to be thrown');
        } catch (\GlobalLogistics\Exceptions\NetworkException $e) {
            $this->assertSame('网络请求失败：boom', $e->getMessage());
            $this->assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $e->getPrevious());
        }

        $this->assertSame(2, $calls);
    }
}
