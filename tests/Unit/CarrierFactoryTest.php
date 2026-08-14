<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\CarrierFactory;
use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Channel;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\CarrierNotFoundException;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class CarrierFactoryTest extends TestCase
{
    public function testCreateReturnsRegisteredCarrier(): void
    {
        $registry = ['domestic' => ['fake' => FakeCarrier::class]];
        $factory = new CarrierFactory($registry, new Config([]), new FakeHttpClient());

        $this->assertInstanceOf(FakeCarrier::class, $factory->create(Channel::Domestic, 'fake'));
    }

    public function testUnknownCarrierThrows(): void
    {
        $factory = new CarrierFactory([], new Config([]), new FakeHttpClient());

        $this->expectException(CarrierNotFoundException::class);
        $factory->create(Channel::Domestic, 'nope');
    }

    public function testWrongChannelThrows(): void
    {
        $registry = ['domestic' => ['fake' => FakeCarrier::class]];
        $factory = new CarrierFactory($registry, new Config([]), new FakeHttpClient());

        $this->expectException(CarrierNotFoundException::class);
        $factory->create(Channel::International, 'fake');
    }
}

final class FakeCarrier implements CarrierInterface
{
    public function __construct(public Config $config, public $http)
    {
    }

    public function queryTrack(string $trackingNo, array $options = []): \GlobalLogistics\Models\Tracking
    {
        throw new \LogicException('not implemented');
    }

    public function createOrder(\GlobalLogistics\Models\OrderRequest $request): \GlobalLogistics\Models\Order
    {
        throw new \LogicException('not implemented');
    }

    public function createLabel(\GlobalLogistics\Models\Order $order, array $options = []): \GlobalLogistics\Models\Label
    {
        throw new \LogicException('not implemented');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
    }
}
