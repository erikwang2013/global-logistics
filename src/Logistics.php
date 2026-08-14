<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Http\HttpClientFactory;
use GlobalLogistics\Http\RetryingClient;
use GlobalLogistics\Models\Tracking;

final class Logistics
{
    private static ?Config $config = null;
    private static ?Detector $detector = null;
    private static ?CarrierFactory $factory = null;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $config 支持键：sf/zto/yto/jt 各家密钥、
     *        http_client（PSR-18 客户端）、registry、detector_rules、max_retries
     */
    public static function configure(array $config): void
    {
        self::$config = new Config($config);
        self::$detector = new Detector($config['detector_rules'] ?? require __DIR__ . '/Resources/detector-rules.php');

        $httpFactory = new HttpClientFactory($config['http_client'] ?? null);
        $http = new RetryingClient($httpFactory->create(), maxRetries: (int) ($config['max_retries'] ?? 2));

        $registry = $config['registry'] ?? require __DIR__ . '/Resources/carrier-registry.php';
        self::$factory = new CarrierFactory(config: self::$config, http: $http, registry: $registry);
    }

    public static function reset(): void
    {
        self::$config = null;
        self::$detector = null;
        self::$factory = null;
    }

    private static function requireInitialized(): void
    {
        if (self::$factory === null || self::$detector === null || self::$config === null) {
            self::configure([]);
        }
    }

    public static function domestic(string $carrierCode): CarrierInterface
    {
        self::requireInitialized();

        return self::$factory->create(Channel::Domestic, $carrierCode);
    }

    public static function international(string $carrierCode): CarrierInterface
    {
        self::requireInitialized();

        return self::$factory->create(Channel::International, $carrierCode);
    }

    public static function track(string $trackingNo): Tracking
    {
        self::requireInitialized();

        return self::domestic(self::$detector->detect($trackingNo)->carrierCode)->queryTrack($trackingNo);
    }

    public static function detect(string $trackingNo): Detection
    {
        self::requireInitialized();

        return self::$detector->detect($trackingNo);
    }
}
