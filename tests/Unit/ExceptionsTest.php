<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\CarrierNotFoundException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\NetworkException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testAllExceptionsExtendBase(): void
    {
        $exceptions = [
            new CarrierNotFoundException('sf'),
            new TrackingNotFoundException('SF123'),
            new AuthException('invalid key'),
            new NetworkException('timeout'),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(LogisticsException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testCarrierNotFoundExceptionCarriesCode(): void
    {
        $e = new CarrierNotFoundException('sf');
        $this->assertSame('sf', $e->getCarrierCode());
    }

    public function testNetworkExceptionImplementsPsrInterface(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/track');
        $e = new NetworkException('boom', $request);

        $this->assertInstanceOf(\Psr\Http\Client\NetworkExceptionInterface::class, $e);
        $this->assertSame($request, $e->getRequest());
    }

    public function testNetworkExceptionWithoutRequestThrowsOnGetRequest(): void
    {
        $e = new NetworkException('boom');

        $this->expectException(\LogicException::class);
        $e->getRequest();
    }
}
