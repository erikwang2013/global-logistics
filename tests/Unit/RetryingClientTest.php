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
}
