<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

class NetworkException extends LogisticsException implements NetworkExceptionInterface
{
    private ?RequestInterface $request;

    public function __construct(
        string $message = '',
        ?RequestInterface $request = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
        $this->request = $request;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request ?? throw new \LogicException('NetworkException 未关联请求对象');
    }
}
