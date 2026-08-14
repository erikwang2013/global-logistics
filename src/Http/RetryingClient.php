<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryingClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly int $maxRetries = 2,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $attempts = 0;
        while (true) {
            try {
                $response = $this->inner->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                $attempts++;

                if ($attempts > $this->maxRetries) {
                    throw $e;
                }

                usleep(200_000 * (2 ** ($attempts - 1))); // 指数退避：200ms, 400ms, ...

                continue;
            }

            $attempts++;

            if ($response->getStatusCode() < 500 || $attempts > $this->maxRetries) {
                return $response;
            }

            usleep(200_000 * (2 ** ($attempts - 1))); // 指数退避：200ms, 400ms, ...
        }
    }
}
