<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    /** @var callable|null */
    public $handler;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->handler === null) {
            throw new \RuntimeException('FakeHttpClient handler not configured');
        }

        return ($this->handler)($request);
    }
}
