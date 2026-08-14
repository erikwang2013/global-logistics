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
}
