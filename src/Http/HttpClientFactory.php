<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use Psr\Http\Client\ClientInterface;

final class HttpClientFactory
{
    public function __construct(private readonly ?ClientInterface $injected = null)
    {
    }

    public function create(): ClientInterface
    {
        if ($this->injected !== null) {
            return $this->injected;
        }

        return new \GuzzleHttp\Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }
}
