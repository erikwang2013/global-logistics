# Wave 0 骨架 + 国内 A 批承运商 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 搭建 `erikwang2013/global-logistics` 可用的 composer 包骨架（门面、自动识别、统一异常/模型/状态映射），并实现国内 A 批 4 家承运商（顺丰、中通、圆通、极兔）的轨迹查询适配器作为模式样板。

**Architecture:** 统一门面 + 适配器模式。`Logistics::track()` 通过 Detector 自动识别通道（国内/国际）与承运商，CarrierFactory 按注册表创建适配器；每家承运商实现 `CarrierInterface`，把各家 API 响应归一化为统一 `Tracking` 模型。本计划仅覆盖 Wave 0 骨架 + 国内 A 批；国内 B、国际 A/B、Wave 2 批次由后续计划实现。

**Tech Stack:** PHP 8.1+（enum/readonly）、PSR-4、PSR-18 HTTP（Guzzle 7）、PHPUnit 10、GitHub Actions CI。

**参考设计文档：** `docs/superpowers/specs/2026-08-14-global-logistics-design.md`

**承运商代码约定：** `sf`=顺丰、`zto`=中通、`yto`=圆通、`jt`=极兔。

---

## 文件结构

| 文件 | 职责 |
|------|------|
| `composer.json` | 包定义：PSR-4 `GlobalLogistics\` → `src/`，PHP ^8.1，guzzle ^7.7，phpunit ^10.5 |
| `phpunit.xml.dist` | PHPUnit 配置（tests/ 目录、`@live` 排除） |
| `.github/workflows/ci.yml` | PHP 8.1/8.2/8.3 矩阵跑测试 |
| `src/Channel.php` | 枚举：Domestic/International（string-backed） |
| `src/Support/TrackStatus.php` | 枚举：PENDING/IN_TRANSIT/OUT_FOR_DELIVERY/DELIVERED/EXCEPTION/RETURNED/UNKNOWN |
| `src/Exceptions/*.php` | LogisticsException 基类 + CarrierNotFoundException、TrackingNotFoundException、AuthException、NetworkException |
| `src/Models/Tracking.php` | 轨迹结果模型 |
| `src/Models/TrackingEvent.php` | 单条轨迹事件模型 |
| `src/Models/OrderRequest.php` | 下单请求模型（sender/receiver/package 数组） |
| `src/Models/Order.php` | 下单结果模型 |
| `src/Models/Label.php` | 电子面单模型 |
| `src/Detection.php` | 自动识别结果（channel + carrierCode） |
| `src/Config.php` | 配置装载器（点号 key，如 `sf.app_key`） |
| `src/Http/HttpClientFactory.php` | PSR-18 客户端工厂（可注入 mock，默认 Guzzle） |
| `src/Http/RetryingClient.php` | 指数退避重试包装（默认 2 次） |
| `src/CarrierInterface.php` | 统一承运商接口（queryTrack/createOrder/createLabel/subscribe） |
| `src/CarrierFactory.php` | 按 Channel + code 创建适配器，未注册抛异常 |
| `src/Detector.php` | 单号自动识别器（读规则表） |
| `src/Logistics.php` | 门面入口（configure/domestic/international/track/detect） |
| `src/Carriers/Domestic/Sf.php` | 顺丰丰桥适配器 |
| `src/Carriers/Domestic/Zto.php` | 中通开放平台适配器 |
| `src/Carriers/Domestic/Yto.php` | 圆通开放平台适配器 |
| `src/Carriers/Domestic/Jt.php` | 极兔开放平台适配器 |
| `src/Resources/carrier-registry.php` | channel → code → class 映射表 |
| `src/Resources/detector-rules.php` | 单号识别规则表 |
| `tests/Support/FakeHttpClient.php` | 测试用 PSR-18 mock 客户端 |
| `tests/Unit/*Test.php` | 核心单元测试 |
| `tests/Carriers/{Sf,Zto,Yto,Jt}Test.php` | 承运商适配器测试 |
| `tests/fixtures/{sf,zto,yto,jt}/*.json` | 各家响应样例 |
| `README.md`、`LICENSE` | 文档与 MIT 许可 |

---

### Task 1: 项目初始化

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `.gitignore`

- [ ] **Step 1: 写 composer.json**

```json
{
    "name": "erikwang2013/global-logistics",
    "description": "Unified facade for domestic (China) express and international logistics tracking APIs",
    "type": "library",
    "license": "MIT",
    "keywords": ["logistics", "express", "tracking", "courier", "快递", "物流"],
    "require": {
        "php": "^8.1",
        "guzzlehttp/guzzle": "^7.7"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "GlobalLogistics\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "GlobalLogistics\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit"
    },
    "minimum-stability": "stable"
}
```

- [ ] **Step 2: 写 phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="carriers">
            <directory>tests/Carriers</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="LIVE_TESTS" value="0"/>
    </php>
</phpunit>
```

- [ ] **Step 3: 写 .gitignore**

```
/vendor/
composer.lock
.phpunit.result.cache
```

- [ ] **Step 4: 安装依赖**

Run: `composer install`
Expected: `Generating autoload files`，无错误。

- [ ] **Step 5: 跑空测试套件确认可运行**

Run: `vendor/bin/phpunit --version`
Expected: PHPUnit 10.x 版本输出。

- [ ] **Step 6: Commit**

```bash
git add composer.json phpunit.xml.dist .gitignore
git commit -m "chore: initialize composer package skeleton"
```

---

### Task 2: 枚举与异常体系

**Files:**
- Create: `src/Channel.php`
- Create: `src/Support/TrackStatus.php`
- Create: `src/Exceptions/LogisticsException.php`
- Create: `src/Exceptions/CarrierNotFoundException.php`
- Create: `src/Exceptions/TrackingNotFoundException.php`
- Create: `src/Exceptions/AuthException.php`
- Create: `src/Exceptions/NetworkException.php`
- Test: `tests/Unit/ChannelTest.php`
- Test: `tests/Unit/TrackStatusTest.php`
- Test: `tests/Unit/ExceptionsTest.php`

- [ ] **Step 1: 写失败测试（三个测试文件）**

`tests/Unit/ChannelTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Channel;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    public function testDomesticValue(): void
    {
        $this->assertSame('domestic', Channel::Domestic->value);
    }

    public function testInternationalValue(): void
    {
        $this->assertSame('international', Channel::International->value);
    }

    public function testFromValue(): void
    {
        $this->assertSame(Channel::Domestic, Channel::from('domestic'));
        $this->assertSame(Channel::International, Channel::from('international'));
    }
}
```

`tests/Unit/TrackStatusTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Support\TrackStatus;
use PHPUnit\Framework\TestCase;

final class TrackStatusTest extends TestCase
{
    public function testAllStatusesExist(): void
    {
        $this->assertSame(
            ['PENDING', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'EXCEPTION', 'RETURNED', 'UNKNOWN'],
            array_map(fn ($case) => $case->name, TrackStatus::cases())
        );
    }

    public function testFromValue(): void
    {
        $this->assertSame(TrackStatus::DELIVERED, TrackStatus::from('DELIVERED'));
    }
}
```

`tests/Unit/ExceptionsTest.php`：

```php
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
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit`
Expected: FAIL — 类不存在（`Class "GlobalLogistics\Channel" not found`）。

- [ ] **Step 3: 写实现**

`src/Channel.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

enum Channel: string
{
    case Domestic = 'domestic';
    case International = 'international';
}
```

`src/Support/TrackStatus.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Support;

enum TrackStatus
{
    case PENDING;
    case IN_TRANSIT;
    case OUT_FOR_DELIVERY;
    case DELIVERED;
    case EXCEPTION;
    case RETURNED;
    case UNKNOWN;
}
```

`src/Exceptions/LogisticsException.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class LogisticsException extends \RuntimeException
{
}
```

`src/Exceptions/CarrierNotFoundException.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class CarrierNotFoundException extends LogisticsException
{
    public function __construct(private readonly string $carrierCode)
    {
        parent::__construct(sprintf('Carrier "%s" not found or cannot be auto-detected', $carrierCode));
    }

    public function getCarrierCode(): string
    {
        return $this->carrierCode;
    }
}
```

`src/Exceptions/TrackingNotFoundException.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class TrackingNotFoundException extends LogisticsException
{
}
```

`src/Exceptions/AuthException.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class AuthException extends LogisticsException
{
}
```

`src/Exceptions/NetworkException.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class NetworkException extends LogisticsException
{
}
```

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit`
Expected: PASS（3 个测试）。

- [ ] **Step 5: Commit**

```bash
git add src/Channel.php src/Support/TrackStatus.php src/Exceptions tests/Unit/ChannelTest.php tests/Unit/TrackStatusTest.php tests/Unit/ExceptionsTest.php
git commit -m "feat: add channel/status enums and exception hierarchy"
```

---

### Task 3: 数据模型

**Files:**
- Create: `src/Models/Tracking.php`
- Create: `src/Models/TrackingEvent.php`
- Create: `src/Models/OrderRequest.php`
- Create: `src/Models/Order.php`
- Create: `src/Models/Label.php`
- Test: `tests/Unit/ModelsTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/ModelsTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use PHPUnit\Framework\TestCase;

final class ModelsTest extends TestCase
{
    public function testTrackingEvent(): void
    {
        $event = new TrackingEvent(
            new \DateTimeImmutable('2026-08-14 10:00:00'),
            '深圳市',
            '快件已到达【深圳】',
            TrackStatus::IN_TRANSIT,
        );

        $this->assertSame('2026-08-14 10:00:00', $event->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('深圳市', $event->location);
        $this->assertSame('快件已到达【深圳】', $event->description);
        $this->assertSame(TrackStatus::IN_TRANSIT, $event->status);
    }

    public function testTracking(): void
    {
        $event = new TrackingEvent(null, '始发地', '已揽收', TrackStatus::PENDING);
        $tracking = new Tracking(
            'sf',
            'SF1234567890',
            TrackStatus::IN_TRANSIT,
            [$event],
            null,
            new \DateTimeImmutable('2026-08-15 18:00:00'),
            '快件已到达【深圳】',
            '运输中',
        );

        $this->assertSame('sf', $tracking->carrierCode);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertCount(1, $tracking->events);
        $this->assertSame('2026-08-15 18:00:00', $tracking->estimatedDeliveryAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已到达【深圳】', $tracking->latestDescription);
        $this->assertSame('运输中', $tracking->rawStatus);
    }

    public function testOrderRequestAndOrder(): void
    {
        $request = new OrderRequest(
            ['name' => '张三', 'phone' => '13800138000', 'address' => '深圳市南山区'],
            ['name' => '李四', 'phone' => '13900139000', 'address' => '北京市朝阳区'],
            ['weight' => 1.5, 'items' => [['name' => '书', 'qty' => 1]]],
        );
        $order = new Order('SF1234567890', 'TMS_LABEL', ['raw' => true]);

        $this->assertSame('张三', $request->sender['name']);
        $this->assertSame('SF1234567890', $order->trackingNo);
        $this->assertSame('TMS_LABEL', $order->labelContent);
        $this->assertSame(['raw' => true], $order->raw);
    }

    public function testLabel(): void
    {
        $label = new Label('pdf', 'JVBERi0xLjQ=', []);
        $this->assertSame('pdf', $label->format);
        $this->assertSame('JVBERi0xLjQ=', $label->content);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/ModelsTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 3: 写实现**

`src/Models/TrackingEvent.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

use GlobalLogistics\Support\TrackStatus;

final readonly class TrackingEvent
{
    public function __construct(
        public ?\DateTimeImmutable $occurredAt,
        public string $location,
        public string $description,
        public TrackStatus $status,
        public array $raw = [],
    ) {
    }
}
```

`src/Models/Tracking.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

use GlobalLogistics\Support\TrackStatus;

final readonly class Tracking
{
    /**
     * @param TrackingEvent[] $events
     */
    public function __construct(
        public string $carrierCode,
        public string $trackingNo,
        public TrackStatus $status,
        public array $events = [],
        public ?\DateTimeImmutable $deliveredAt = null,
        public ?\DateTimeImmutable $estimatedDeliveryAt = null,
        public string $latestDescription = '',
        public string $rawStatus = '',
        public array $raw = [],
    ) {
    }
}
```

`src/Models/OrderRequest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class OrderRequest
{
    /**
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $receiver
     * @param array<string, mixed> $package
     * @param array<string, mixed> $options
     */
    public function __construct(
        public array $sender,
        public array $receiver,
        public array $package = [],
        public array $options = [],
    ) {
    }
}
```

`src/Models/Order.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class Order
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $trackingNo,
        public ?string $labelContent = null,
        public array $raw = [],
    ) {
    }
}
```

`src/Models/Label.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Models;

final readonly class Label
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $format,
        public string $content,
        public array $raw = [],
    ) {
    }
}
```

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/ModelsTest.php`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add src/Models tests/Unit/ModelsTest.php
git commit -m "feat: add tracking/order/label models"
```

---

### Task 4: Config 与 HTTP 客户端

**Files:**
- Create: `src/Config.php`
- Create: `src/Http/HttpClientFactory.php`
- Create: `src/Http/RetryingClient.php`
- Test: `tests/Unit/ConfigTest.php`
- Test: `tests/Unit/RetryingClientTest.php`
- Create: `tests/Support/FakeHttpClient.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/ConfigTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotNotationGet(): void
    {
        $config = new Config(['sf' => ['app_key' => 'abc', 'checkword' => 'xyz']]);

        $this->assertSame('abc', $config->get('sf.app_key'));
        $this->assertSame('xyz', $config->get('sf.checkword'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        $config = new Config([]);
        $this->assertNull($config->get('nope'));
        $this->assertSame('fallback', $config->get('nope', 'fallback'));
    }

    public function testAll(): void
    {
        $config = new Config(['a' => 1]);
        $this->assertSame(['a' => 1], $config->all());
    }
}
```

`tests/Support/FakeHttpClient.php`（先创建，供后续所有承运商测试复用）：

```php
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
```

`tests/Unit/RetryingClientTest.php`：

```php
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
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/ConfigTest.php tests/Unit/RetryingClientTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 3: 写实现**

`src/Config.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function all(): array
    {
        return $this->values;
    }
}
```

`src/Http/HttpClientFactory.php`：

```php
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
```

`src/Http/RetryingClient.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use Psr\Http\Client\ClientInterface;
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
            $response = $this->inner->sendRequest($request);
            $attempts++;

            if ($response->getStatusCode() < 500 || $attempts > $this->maxRetries) {
                return $response;
            }

            usleep(200_000 * (2 ** ($attempts - 1))); // 指数退避：200ms, 400ms, ...
        }
    }
}
```

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add src/Config.php src/Http tests/Unit/ConfigTest.php tests/Unit/RetryingClientTest.php tests/Support/FakeHttpClient.php
git commit -m "feat: add config loader and PSR-18 http client with retry"
```

---

### Task 5: 承运商接口、注册表与工厂

**Files:**
- Create: `src/CarrierInterface.php`
- Create: `src/Resources/carrier-registry.php`
- Create: `src/CarrierFactory.php`
- Test: `tests/Unit/CarrierFactoryTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/CarrierFactoryTest.php`：

```php
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
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/CarrierFactoryTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 3: 写实现**

`src/CarrierInterface.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;

interface CarrierInterface
{
    public function queryTrack(string $trackingNo, array $options = []): Tracking;

    public function createOrder(OrderRequest $request): Order;

    public function createLabel(Order $order, array $options = []): Label;

    public function subscribe(string $callbackUrl, array $options = []): void;
}
```

`src/Resources/carrier-registry.php`：

```php
<?php

declare(strict_types=1);

use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zto;

// channel => code => adapter class
return [
    'domestic' => [
        'sf' => Sf::class,
        'zto' => Zto::class,
        'yto' => Yto::class,
        'jt' => Jt::class,
    ],
    'international' => [],
];
```

> 注：引用尚不存在的 Sf/Zto/Yto/Jt 类在运行时才解析，注册表文件本身可先落盘；工厂测试用 FakeCarrier 不触碰默认注册表。

`src/CarrierFactory.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Exceptions\CarrierNotFoundException;
use Psr\Http\Client\ClientInterface;

final class CarrierFactory
{
    /**
     * @param array<string, array<string, class-string<CarrierInterface>>> $registry
     */
    public function __construct(
        private readonly array $registry = [],
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public static function withDefaults(Config $config, ClientInterface $http): self
    {
        return new self(require __DIR__ . '/Resources/carrier-registry.php', $config, $http);
    }

    public function create(Channel $channel, string $carrierCode): CarrierInterface
    {
        $class = $this->registry[$channel->value][$carrierCode] ?? null;
        if ($class === null) {
            throw new CarrierNotFoundException($carrierCode);
        }

        return new $class($this->config, $this->http);
    }
}
```

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/CarrierFactoryTest.php`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add src/CarrierInterface.php src/Resources/carrier-registry.php src/CarrierFactory.php tests/Unit/CarrierFactoryTest.php
git commit -m "feat: add carrier interface, registry and factory"
```

---

### Task 6: 单号自动识别器

**Files:**
- Create: `src/Detection.php`
- Create: `src/Resources/detector-rules.php`
- Create: `src/Detector.php`
- Test: `tests/Unit/DetectorTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/DetectorTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Channel;
use GlobalLogistics\Detector;
use GlobalLogistics\Exceptions\CarrierNotFoundException;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    public function testDetectsSfDomestic(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('SF1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sf', $result->carrierCode);
    }

    public function testDetectsDhlInternational(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DHL1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('dhl', $result->carrierCode);
    }

    public function testUnknownThrows(): void
    {
        $detector = Detector::withDefaults();

        $this->expectException(CarrierNotFoundException::class);
        $detector->detect('ZZZ99999999');
    }

    public function testCustomRules(): void
    {
        $detector = new Detector([
            '/^AB\d{8}$/' => ['domestic', 'ab'],
        ]);
        $result = $detector->detect('AB12345678');

        $this->assertSame('ab', $result->carrierCode);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/DetectorTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 3: 写实现**

`src/Detection.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

final readonly class Detection
{
    public function __construct(
        public Channel $channel,
        public string $carrierCode,
    ) {
    }
}
```

`src/Resources/detector-rules.php`：

```php
<?php

declare(strict_types=1);

// pattern => [channel, carrierCode]；channel 取值 'domestic' | 'international'
return [
    '/^SF\d{10,12}$/i' => ['domestic', 'sf'],
    '/^JT\d{10,15}$/i' => ['domestic', 'jt'],
    '/^\d{13}$/' => ['domestic', 'zto'],
    '/^YT\d{10,12}$/i' => ['domestic', 'yto'],
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^[A-Z]{2}\d{9}[A-Z]{2}$/i' => ['international', 'fedex'],
    '/^GM\d{9}$/i' => ['international', 'dhl'],
    '/^LH\d{10,12}$/i' => ['international', 'dhl'],
    '/^RR\d{12}$/i' => ['international', 'royal-mail'],
];
```

`src/Detector.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics;

use GlobalLogistics\Exceptions\CarrierNotFoundException;

final class Detector
{
    /**
     * @param array<string, array{0: string, 1: string}> $rules pattern => [channel, carrier]
     */
    public function __construct(private readonly array $rules = [])
    {
    }

    public static function withDefaults(): self
    {
        return new self(require __DIR__ . '/Resources/detector-rules.php');
    }

    public function detect(string $trackingNo): Detection
    {
        foreach ($this->rules as $pattern => [$channel, $carrier]) {
            if (preg_match($pattern, $trackingNo) === 1) {
                return new Detection(Channel::from($channel), $carrier);
            }
        }

        throw new CarrierNotFoundException($trackingNo);
    }
}
```

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit/DetectorTest.php`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add src/Detection.php src/Resources/detector-rules.php src/Detector.php tests/Unit/DetectorTest.php
git commit -m "feat: add tracking number auto-detector"
```

---

### Task 7: Logistics 门面

**Files:**
- Create: `src/Logistics.php`
- Test: `tests/Unit/LogisticsTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/LogisticsTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Channel;
use GlobalLogistics\Config;
use GlobalLogistics\Logistics;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class LogisticsTest extends TestCase
{
    protected function setUp(): void
    {
        Logistics::reset();
    }

    public function testConfigureAndDetect(): void
    {
        Logistics::configure(['http_client' => new FakeHttpClient()]);

        $result = Logistics::detect('SF1234567890');
        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sf', $result->carrierCode);
    }

    public function testTrackAutoDetectsAndReturnsTracking(): void
    {
        Logistics::configure(['http_client' => new FakeHttpClient(), 'registry' => [
            'domestic' => ['sf' => StubCarrier::class],
        ]]);

        $tracking = Logistics::track('SF1234567890');
        $this->assertSame('sf', $tracking->carrierCode);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
    }

    public function testDomesticExplicit(): void
    {
        Logistics::configure(['registry' => [
            'domestic' => ['sf' => StubCarrier::class],
        ]]);

        $this->assertInstanceOf(StubCarrier::class, Logistics::domestic('sf'));
    }
}

final class StubCarrier implements CarrierInterface
{
    public function __construct(public Config $config, public $http)
    {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        return new Tracking('sf', $trackingNo, TrackStatus::IN_TRANSIT);
    }

    public function createOrder(OrderRequest $request): Order
    {
        return new Order('SF1234567890');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        return new Label('pdf', '');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Unit/LogisticsTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 3: 写实现**

`src/Logistics.php`：

```php
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
        self::$factory = new CarrierFactory($registry, self::$config, $http);
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
```

> 注：`Logistics::track()` 目前按检测到的 carrierCode 走 domestic 通道——本计划批次仅含国内承运商，国际通道批次接入后需改为按 `Detection->channel` 选择通道（见 Task 12 后续说明）。

- [ ] **Step 4: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Unit`
Expected: PASS。

- [ ] **Step 5: Commit**

```bash
git add src/Logistics.php tests/Unit/LogisticsTest.php
git commit -m "feat: add Logistics facade with configure/track/detect"
```

---

### Task 8: 顺丰（sf）适配器 — 模式样板

**Files:**
- Create: `tests/fixtures/sf/track-in-transit.json`
- Create: `tests/fixtures/sf/track-delivered.json`
- Create: `tests/fixtures/sf/error-invalid-key.json`
- Create: `src/Carriers/Domestic/Sf.php`
- Test: `tests/Carriers/SfTest.php`

背景：顺丰丰桥开放平台（open.sf-express.com）。本任务实现轨迹查询（serviceCode=`EXP_RECE_SEARCH`）+ 订阅（`EXP_RECE_SUBSCRIBE`）+ 回调验签。下单/面单在后续任务实现。

- [ ] **Step 1: 查阅官方文档确认接口细节**

Run（或人工查阅）: `WebFetch https://open.sf-express.com/sf/#/document` — 确认 `EXP_RECE_SEARCH` 的请求字段（partnerID、requestID、serviceCode、timestamp、msgData、msgDigest）与签名算法：`msgDigest = base64(md5(msgData + checkword, true))`。
若文档确认 timestamp 单位为毫秒，使用 `(int) round(microtime(true) * 1000)`。

- [ ] **Step 2: 写 fixtures**

`tests/fixtures/sf/track-in-transit.json`：

```json
{
    "apiErrorMsg": "",
    "apiResponseID": "R-20260814-0001",
    "apiResultCode": "A1000",
    "apiErrorCode": "",
    "success": true,
    "msgData": {
        "waybillRouteInfoList": [
            {
                "waybillNo": "SF1234567890",
                "waybillRouteInfo": [
                    {
                        "acceptTime": "2026-08-14 08:00:00",
                        "acceptAddress": "深圳市南山区",
                        "remark": "快件已揽收",
                        "opcode": "50"
                    },
                    {
                        "acceptTime": "2026-08-14 10:30:00",
                        "acceptAddress": "深圳转运中心",
                        "remark": "快件已到达【深圳转运中心】",
                        "opcode": "60"
                    }
                ]
            }
        ]
    }
}
```

`tests/fixtures/sf/track-delivered.json`：

```json
{
    "apiErrorMsg": "",
    "apiResponseID": "R-20260814-0002",
    "apiResultCode": "A1000",
    "apiErrorCode": "",
    "success": true,
    "msgData": {
        "waybillRouteInfoList": [
            {
                "waybillNo": "SF1234567890",
                "waybillRouteInfo": [
                    {
                        "acceptTime": "2026-08-14 08:00:00",
                        "acceptAddress": "深圳市南山区",
                        "remark": "快件已揽收",
                        "opcode": "50"
                    },
                    {
                        "acceptTime": "2026-08-15 14:00:00",
                        "acceptAddress": "北京市朝阳区",
                        "remark": "快件已签收，签收人：李四",
                        "opcode": "8000"
                    }
                ]
            }
        ]
    }
}
```

`tests/fixtures/sf/error-invalid-key.json`：

```json
{
    "apiErrorMsg": "无效的合作伙伴编码",
    "apiResponseID": "R-20260814-0003",
    "apiResultCode": "8000",
    "apiErrorCode": "8000",
    "success": false,
    "msgData": ""
}
```

- [ ] **Step 3: 写失败测试**

`tests/Carriers/SfTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SfTest extends TestCase
{
    private function makeAdapter(string $fixture): Sf
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use ($fixture) {
            $body = json_decode((string) $request->getBody(), true);

            // 校验请求体含签名与关键字段
            assert(isset($body['partnerID']) && $body['partnerID'] === 'test-partner');
            assert(isset($body['msgDigest']) && is_string($body['msgDigest']) && $body['msgDigest'] !== '');
            assert(isset($body['serviceCode']) && $body['serviceCode'] === 'EXP_RECE_SEARCH');
            $msg = json_decode($body['msgData'], true);
            assert($msg['trackingNumber'] === 'SF1234567890');

            $content = file_get_contents(__DIR__ . '/../fixtures/sf/' . $fixture);

            return new Response(200, ['Content-Type' => 'application/json'], $content);
        };

        return new Sf(
            new Config(['sf' => ['partner_id' => 'test-partner', 'checkword' => 'test-checkword']]),
            $http,
        );
    }

    public function testQueryTrackParsesInTransit(): void
    {
        $tracking = $this->makeAdapter('track-in-transit.json')->queryTrack('SF1234567890');

        $this->assertSame(TrackStatus::IN_TRANSIT, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('2026-08-14 08:00:00', $tracking->events[0]->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('深圳市南山区', $tracking->events[0]->location);
        $this->assertSame('快件已揽收', $tracking->events[0]->description);
        $this->assertSame('SF1234567890', $tracking->trackingNo);
    }

    public function testQueryTrackParsesDelivered(): void
    {
        $tracking = $this->makeAdapter('track-delivered.json')->queryTrack('SF1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertSame('2026-08-15 14:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
        $this->assertSame('快件已签收，签收人：李四', $tracking->latestDescription);
    }

    public function testAuthErrorMapped(): void
    {
        $this->expectException(AuthException::class);
        $this->makeAdapter('error-invalid-key.json')->queryTrack('SF1234567890');
    }

    public function testSignatureVerification(): void
    {
        $sf = new Sf(new Config(['sf' => ['checkword' => 'test-checkword']]), new FakeHttpClient());
        // 用与签名相同的算法生成合法签名
        $payload = '{"event":"push"}';
        $digest = base64_encode(md5($payload . 'test-checkword', true));

        $this->assertTrue($sf->verifyCallbackSignature($payload, $digest));
        $this->assertFalse($sf->verifyCallbackSignature($payload, 'wrong'));
    }
}
```

- [ ] **Step 4: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Carriers/SfTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 5: 写实现**

`src/Carriers/Domestic/Sf.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Sf implements CarrierInterface
{
    private const ENDPOINT = 'https://sfapi.sf-express.com/std/service';

    /** opcode => 统一状态（顺丰路由 opcode 语义，以官方文档为准） */
    private const STATUS_MAP = [
        '50' => TrackStatus::PENDING,           // 已揽收
        '60' => TrackStatus::IN_TRANSIT,        // 运输中
        '70' => TrackStatus::OUT_FOR_DELIVERY,  // 派送中
        '8000' => TrackStatus::DELIVERED,       // 已签收
        '9010' => TrackStatus::EXCEPTION,       // 异常
        '9000' => TrackStatus::RETURNED,        // 退回
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $msgData = [
            'trackingType' => '1',
            'trackingNumber' => $trackingNo,
            'methodType' => '1',
            'checkPhoneNo' => $options['phone'] ?? '',
        ];

        $response = $this->post('EXP_RECE_SEARCH', $msgData);
        $data = json_decode((string) $response->getBody(), true);

        if (($data['success'] ?? false) !== true) {
            $this->throwForApiError($data);
        }

        $routeList = $data['msgData']['waybillRouteInfoList'][0]['waybillRouteInfo'] ?? [];
        if ($routeList === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $routeList);
        $latest = $events[count($events) - 1];
        $lastRaw = $routeList[count($routeList) - 1];

        return new Tracking(
            carrierCode: 'sf',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($lastRaw['opcode'] ?? ''),
            raw: $data,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('SF createOrder 待实现（需丰桥下单接口开通）');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('SF createLabel 待实现（需丰桥电子面单接口开通）');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        $msgData = [
            'trackingType' => '1',
            'trackingNumber' => $options['tracking_no'] ?? '',
            'callbackUrl' => $callbackUrl,
        ];
        $this->post('EXP_RECE_SUBSCRIBE', $msgData);
    }

    public function verifyCallbackSignature(string $payload, string $digest): bool
    {
        $expected = base64_encode(md5($payload . $this->config->get('sf.checkword', ''), true));

        return hash_equals($expected, $digest);
    }

    /** @param array<string, mixed> $msgData */
    private function post(string $serviceCode, array $msgData): \Psr\Http\Message\ResponseInterface
    {
        $json = json_encode($msgData, JSON_UNESCAPED_UNICODE);
        $body = json_encode([
            'partnerID' => $this->config->get('sf.partner_id'),
            'requestID' => bin2hex(random_bytes(8)),
            'serviceCode' => $serviceCode,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
            'msgData' => $json,
            'msgDigest' => base64_encode(md5($json . $this->config->get('sf.checkword', ''), true)),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], $body);

        return $this->http->sendRequest($request);
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['acceptTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['acceptAddress'] ?? ''),
            description: (string) ($row['remark'] ?? ''),
            status: self::STATUS_MAP[(string) ($row['opcode'] ?? '')] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }

    /** @param array<string, mixed> $data */
    private function throwForApiError(array $data): never
    {
        $code = (string) ($data['apiResultCode'] ?? '');
        $message = (string) ($data['apiErrorMsg'] ?? '请求失败');

        if ($code === '8000' || $code === '8001' || $code === '8002') {
            throw new AuthException(sprintf('[SF %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[SF %s] %s', $code, $message));
    }
}
```

- [ ] **Step 6: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Carriers/SfTest.php`
Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add src/Carriers/Domestic/Sf.php tests/Carriers/SfTest.php tests/fixtures/sf
git commit -m "feat: add SF Express (sf) adapter with track/subscribe/verify"
```

---

### Task 9: 中通（zto）适配器

**Files:**
- Create: `tests/fixtures/zto/track.json`
- Create: `src/Carriers/Domestic/Zto.php`
- Test: `tests/Carriers/ZtoTest.php`

背景：中通开放平台（open.zto.com）。轨迹接口 POST `https://openapi.zto.com/trace/queryTrack`，请求 JSON：`{companyId, msgType: 'TRACK', data: <查询JSON字符串>, dataDigest: md5(data + secret), timestamp}`；响应 `{status: '200', message, data: {traces: [{date, desc, status}]}}`（具体字段以官方文档为准）。

- [ ] **Step 1: 查阅官方文档确认接口细节**

Run（或人工查阅）: `WebFetch https://open.zto.com/document` — 确认轨迹查询接口路径、`data` 内字段（`billNo`）、响应轨迹数组字段名与 `statusCode` 语义、签名算法（`dataDigest = md5(data + secret)`）。按文档调整下方代码中的字段名。

- [ ] **Step 2: 写 fixture**

`tests/fixtures/zto/track.json`：

```json
{
    "status": "200",
    "message": "成功",
    "data": {
        "billNo": "ZTO1234567890",
        "traces": [
            {
                "date": "2026-08-14 09:00:00",
                "desc": "【广州市】快件已到达【广州转运中心】",
                "status": "运输中"
            },
            {
                "date": "2026-08-15 16:00:00",
                "desc": "【北京市】已签收，签收人：王五",
                "status": "已签收"
            }
        ]
    }
}
```

- [ ] **Step 3: 写失败测试**

`tests/Carriers/ZtoTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ZtoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert($body['companyId'] === 'test-company');
            assert($body['msgType'] === 'TRACK');
            assert(isset($body['dataDigest']) && $body['dataDigest'] !== '');
            assert(json_decode($body['data'], true)['billNo'] === 'ZTO1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/zto/track.json'));
        };

        $adapter = new Zto(
            new Config(['zto' => ['company_id' => 'test-company', 'secret' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('ZTO1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('【广州市】快件已到达【广州转运中心】', $tracking->events[0]->description);
        $this->assertSame('2026-08-15 16:00:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 4: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Carriers/ZtoTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 5: 写实现**

`src/Carriers/Domestic/Zto.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Zto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.zto.com/trace/queryTrack';

    /** 中通原始状态词 => 统一状态 */
    private const STATUS_MAP = [
        '已揽收' => TrackStatus::PENDING,
        '运输中' => TrackStatus::IN_TRANSIT,
        '派送中' => TrackStatus::OUT_FOR_DELIVERY,
        '已签收' => TrackStatus::DELIVERED,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $data = json_encode(['billNo' => $trackingNo], JSON_UNESCAPED_UNICODE);
        $body = json_encode([
            'companyId' => $this->config->get('zto.company_id'),
            'msgType' => 'TRACK',
            'data' => $data,
            'dataDigest' => md5($data . $this->config->get('zto.secret', '')),
            'timestamp' => (string) time(),
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        $status = (string) ($result['status'] ?? '');
        if ($status !== '200' && $status !== '') {
            $this->throwForApiError($status, (string) ($result['message'] ?? ''));
        }

        $traces = $result['data']['traces'] ?? [];
        if ($traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $traces);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'zto',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('ZTO createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('ZTO createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('ZTO subscribe 待实现（中通推送服务开通后按文档接入）');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['date'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: '',
            description: (string) ($row['desc'] ?? ''),
            status: self::STATUS_MAP[(string) ($row['status'] ?? '')] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', '50001'], true)) {
            throw new AuthException(sprintf('[ZTO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[ZTO %s] %s', $code, $message));
    }
}
```

- [ ] **Step 6: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Carriers/ZtoTest.php`
Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add src/Carriers/Domestic/Zto.php tests/Carriers/ZtoTest.php tests/fixtures/zto
git commit -m "feat: add ZTO Express (zto) adapter with track query"
```

---

### Task 10: 圆通（yto）适配器

**Files:**
- Create: `tests/fixtures/yto/track.json`
- Create: `src/Carriers/Domestic/Yto.php`
- Test: `tests/Carriers/YtoTest.php`

背景：圆通开放平台（open.yto.com）。轨迹接口 POST `https://openapi.yto.com/openapi/queryTrace`，请求参数：`app_key`、`timestamp`、`sign = md5(按规则拼接参数 + secret)`，body JSON：`{logisticProviderID: 'YTO', trackingNumber, queryType: '1'}`；响应 `{status: '1', message, trace: [{acceptTime, acceptAddress, remark, opCode}]}`（具体以官方文档为准）。

- [ ] **Step 1: 查阅官方文档确认接口细节**

Run（或人工查阅）: `WebFetch https://open.yto.com/openapiDocs.html` — 确认 queryTrace 的签名规则（`sign` 生成算法）、请求/响应字段名。按文档调整代码。

- [ ] **Step 2: 写 fixture**

`tests/fixtures/yto/track.json`：

```json
{
    "status": "1",
    "message": "成功",
    "trace": [
        {
            "acceptTime": "2026-08-14 10:00:00",
            "acceptAddress": "上海市",
            "remark": "快件已到达【上海转运中心】",
            "opCode": "2"
        },
        {
            "acceptTime": "2026-08-15 18:30:00",
            "acceptAddress": "杭州市",
            "remark": "签收成功",
            "opCode": "2"
        }
    ]
}
```

- [ ] **Step 3: 写失败测试**

`tests/Carriers/YtoTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class YtoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            assert($query['app_key'] === 'test-app-key');
            assert(isset($query['sign']) && $query['sign'] !== '');
            $body = json_decode((string) $request->getBody(), true);
            assert($body['trackingNumber'] === 'YT1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yto/track.json'));
        };

        $adapter = new Yto(
            new Config(['yto' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('上海市', $tracking->events[0]->location);
        $this->assertSame('签收成功', $tracking->latestDescription);
    }
}
```

- [ ] **Step 4: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Carriers/YtoTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 5: 写实现**

`src/Carriers/Domestic/Yto.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Yto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.yto.com/openapi/queryTrace';

    /** 圆通状态关键词 => 统一状态（以 remark 内容匹配） */
    private const STATUS_MAP = [
        '已揽收' => TrackStatus::PENDING,
        '运输中' => TrackStatus::IN_TRANSIT,
        '派送中' => TrackStatus::OUT_FOR_DELIVERY,
        '签收' => TrackStatus::DELIVERED,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $body = json_encode([
            'logisticProviderID' => 'YTO',
            'trackingNumber' => $trackingNo,
            'queryType' => '1',
        ], JSON_UNESCAPED_UNICODE);

        $appKey = (string) $this->config->get('yto.app_key');
        $secret = (string) $this->config->get('yto.app_secret');
        $timestamp = (string) time();
        // 圆通签名：md5(参数名+参数值按序拼接 + secret)，以官方文档为准
        $sign = md5('app_key' . $appKey . 'timestamp' . $timestamp . $secret);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query([
            'app_key' => $appKey,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]), [
            'Content-Type' => 'application/json',
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['message'] ?? ''));
        }

        $traces = $result['trace'] ?? [];
        if ($traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $traces);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yto',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['remark'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('YTO createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('YTO createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('YTO subscribe 待实现（圆通推送服务开通后按文档接入）');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['acceptTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['remark'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['acceptAddress'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['10001', '10002', '10003'], true)) {
            throw new AuthException(sprintf('[YTO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[YTO %s] %s', $code, $message));
    }
}
```

- [ ] **Step 6: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Carriers/YtoTest.php`
Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add src/Carriers/Domestic/Yto.php tests/Carriers/YtoTest.php tests/fixtures/yto
git commit -m "feat: add YTO Express (yto) adapter with track query"
```

---

### Task 11: 极兔（jt）适配器

**Files:**
- Create: `tests/fixtures/jt/track.json`
- Create: `src/Carriers/Domestic/Jt.php`
- Test: `tests/Carriers/JtTest.php`

背景：极兔开放平台（open.jtexpress.cn）。轨迹接口 POST `https://openapi.jtexpress.cn/API/External_GetTraces.json`，请求 JSON：`{sign, timestamp, msg_type: 'GET_TRACES', data: {tracking_number}}`；`sign = md5(secret + timestamp)` 或按文档规则；响应 `{success: true, code: '200', data: [{tracking_number, traces: [{track_time, track_desc, station_name, status}]}]}`（以官方文档为准）。

- [ ] **Step 1: 查阅官方文档确认接口细节**

Run（或人工查阅）: `WebFetch https://open.jtexpress.cn/#/apiDoc` — 确认 `External_GetTraces` 请求结构、签名规则（header `ApiKey` vs body sign）、响应字段名。按文档调整代码。

- [ ] **Step 2: 写 fixture**

`tests/fixtures/jt/track.json`：

```json
{
    "success": true,
    "code": "200",
    "message": "成功",
    "data": [
        {
            "tracking_number": "JT1234567890",
            "traces": [
                {
                    "track_time": "2026-08-14 12:00:00",
                    "track_desc": "【成都市】快件已从【成都转运中心】发出",
                    "station_name": "成都转运中心",
                    "status": "运输中"
                },
                {
                    "track_time": "2026-08-16 09:30:00",
                    "track_desc": "【重庆市】已签收，签收人：赵六",
                    "station_name": "重庆网点",
                    "status": "已签收"
                }
            ]
        }
    ]
}
```

- [ ] **Step 3: 写失败测试**

`tests/Carriers/JtTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Config;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class JtTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert(isset($body['sign']) && $body['sign'] !== '');
            assert(isset($body['timestamp']));
            assert($body['msg_type'] === 'GET_TRACES');
            assert($body['data']['tracking_number'] === 'JT1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/jt/track.json'));
        };

        $adapter = new Jt(
            new Config(['jt' => ['api_key' => 'test-api-key', 'secret' => 'test-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('JT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('成都转运中心', $tracking->events[0]->location);
        $this->assertSame('2026-08-16 09:30:00', $tracking->deliveredAt->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 4: 跑测试确认失败**

Run: `vendor/bin/phpunit tests/Carriers/JtTest.php`
Expected: FAIL — 类不存在。

- [ ] **Step 5: 写实现**

`src/Carriers/Domestic/Jt.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Jt implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.jtexpress.cn/API/External_GetTraces.json';

    /** 极兔原始状态词 => 统一状态 */
    private const STATUS_MAP = [
        '已揽收' => TrackStatus::PENDING,
        '运输中' => TrackStatus::IN_TRANSIT,
        '派送中' => TrackStatus::OUT_FOR_DELIVERY,
        '已签收' => TrackStatus::DELIVERED,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $timestamp = (string) time();
        $sign = md5($this->config->get('jt.secret', '') . $timestamp); // 签名算法以官方文档为准

        $body = json_encode([
            'sign' => $sign,
            'timestamp' => $timestamp,
            'msg_type' => 'GET_TRACES',
            'data' => [
                'tracking_number' => $trackingNo,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
            'ApiKey' => (string) $this->config->get('jt.api_key'),
        ], $body);

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (($result['success'] ?? false) !== true) {
            $this->throwForApiError((string) ($result['code'] ?? ''), (string) ($result['message'] ?? ''));
        }

        $item = $result['data'][0] ?? [];
        $traces = $item['traces'] ?? [];
        if ($traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = array_map(fn (array $row): TrackingEvent => $this->mapEvent($row), $traces);
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'jt',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['status'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('JT createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('JT createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('JT subscribe 待实现（极兔推送服务开通后按文档接入）');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['track_time'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['station_name'] ?? ''),
            description: (string) ($row['track_desc'] ?? ''),
            status: self::STATUS_MAP[(string) ($row['status'] ?? '')] ?? TrackStatus::UNKNOWN,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403', '50001'], true)) {
            throw new AuthException(sprintf('[JT %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[JT %s] %s', $code, $message));
    }
}
```

- [ ] **Step 6: 跑测试确认通过**

Run: `vendor/bin/phpunit tests/Carriers/JtTest.php`
Expected: PASS。

- [ ] **Step 7: Commit**

```bash
git add src/Carriers/Domestic/Jt.php tests/Carriers/JtTest.php tests/fixtures/jt
git commit -m "feat: add J&T Express (jt) adapter with track query"
```

---

### Task 12: README、LICENSE、CI 与收口

**Files:**
- Create: `README.md`
- Create: `LICENSE`
- Create: `.github/workflows/ci.yml`
- Modify: `src/Logistics.php`（按 Detection.channel 选择通道）

- [ ] **Step 1: 修 Logistics::track 按通道选择**

`src/Logistics.php` 中替换 `track()` 方法：

```php
    public static function track(string $trackingNo): Tracking
    {
        self::requireInitialized();

        $detection = self::$detector->detect($trackingNo);
        $carrier = $detection->channel === Channel::Domestic
            ? self::$factory->create(Channel::Domestic, $detection->carrierCode)
            : self::$factory->create(Channel::International, $detection->carrierCode);

        return $carrier->queryTrack($trackingNo);
    }
```

Run: `vendor/bin/phpunit tests/Unit/LogisticsTest.php`
Expected: PASS。

- [ ] **Step 2: 写 README.md**

`README.md`：

```markdown
# global-logistics

统一门面的国内快递 / 国际物流轨迹查询 composer 包（PHP 8.1+，PSR-4，不绑定框架）。

## 快速开始

```bash
composer require erikwang2013/global-logistics
```

```php
<?php

use GlobalLogistics\Logistics;

Logistics::configure([
    'sf' => ['partner_id' => '...', 'checkword' => '...'],
    'zto' => ['company_id' => '...', 'secret' => '...'],
    'yto' => ['app_key' => '...', 'app_secret' => '...'],
    'jt' => ['api_key' => '...', 'secret' => '...'],
]);

// 自动识别通道（国内/国际）与承运商
$tracking = Logistics::track('SF1234567890');

// 显式指定
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');

echo $tracking->status->name;      // DELIVERED
echo $tracking->latestDescription; // 快件已签收
```

## 已接入承运商

- 国内：顺丰(sf)、中通(zto)、圆通(yto)、极兔(jt) —— 轨迹查询可用；下单/面单/订阅按承运商开放情况接入中
- 国际：规划中（DHL/FedEx/UPS/TNT/USPS、专线小包、聚合平台、全球承运商、电商平台物流）

## 开发

```bash
composer install
composer test
```

无需真实密钥即可跑全量测试（适配器测试走 mock HTTP + fixture）。
```

- [ ] **Step 3: 写 LICENSE（MIT）**

`LICENSE`：

```text
MIT License

Copyright (c) 2026 erikwang2013

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 4: 写 CI**

`.github/workflows/ci.yml`：

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/phpunit
```

- [ ] **Step 5: 全量跑测试**

Run: `composer test`
Expected: 全部 PASS（Unit 套件 + Carriers 套件，约 30+ 测试）。

- [ ] **Step 6: Commit**

```bash
git add README.md LICENSE .github/workflows/ci.yml src/Logistics.php
git commit -m "docs: add README/LICENSE; ci: add github actions; fix: route track by detected channel"
```

---

## 自审记录

**Spec 覆盖：**
- 门面/入口区分国内国际 → Task 7、12（`domestic()/international()/track()/detect()`）✓
- 自动识别 → Task 6 Detector + 规则表 ✓
- 统一接口 → Task 5 CarrierInterface ✓
- 异常体系 → Task 2 ✓；状态映射 → Task 2 + 各家 STATUS_MAP ✓
- 模型 → Task 3 ✓；配置 → Task 4 ✓；HTTP/重试 → Task 4 ✓
- 测试策略（mock HTTP + fixture、无密钥可跑）→ 各承运商 Task + FakeHttpClient ✓
- CI 三版本矩阵 → Task 12 ✓
- 国内 A 批 4 家（顺丰/中通/圆通/极兔）→ Task 8-11 ✓
- 下单/面单/订阅 → 接口就位、SF 订阅与验签已实现；其余标注"待实现"抛 LogisticsException（承运商开放情况不一，属后续计划范围）✓
- 国内 B、国际 A/B、Wave 2 批次 → 后续独立计划（本计划范围外）✓

**占位符扫描：** 无 TBD/TODO；"待实现"均为有意的能力分级占位（抛异常而非静默），符合 spec 第 7 节 Tier 分级。

**类型一致性：** `Tracking`/`TrackingEvent` 构造参数顺序在 Task 8-11 与 Task 3 定义一致（named arguments 使用）；`CarrierNotFoundException` 构造签名（string）与 Task 2 一致；`Detection->channel/carrierCode` 与 Task 6/7/12 一致；`Config->get` 点号 key 与各家配置键（`sf.partner_id` 等）一致。

**已知待执行时确认项（非占位，属外部文档依赖）：** 各家开放平台字段名/签名算法以官方文档为准（各 Task Step 1 已含 WebFetch 确认步骤），执行时若与代码注释所示有出入，以官方文档为准修正字段名并同步 fixture。
