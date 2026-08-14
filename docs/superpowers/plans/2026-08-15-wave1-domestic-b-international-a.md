# Wave 1: 国内 B（韵达/申通/京东/EMS）+ 国际 A（DHL/FedEx/UPS/USPS）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为 global-logistics composer 包新增 8 家承运商适配器：国内 B（韵达 yd、申通 sto、京东 jd、EMS ems）+ 国际 A（DHL、FedEx、UPS、USPS），含 OAuth 令牌客户端（OAuthTokenClient）、检测规则、注册表、README 更新，全部 TDD 且无需真实密钥即可测试。

**Architecture:** 沿用 Wave 0 固化模式——每适配器 = ENDPOINT const + STATUS_MAP + mapEvent + throwForApiError + createOrder/createLabel/subscribe 抛待实现异常。国际适配器通过共享的 `OAuthTokenClient`（PSR-18 decorator，lazy token 获取 + 401 刷新重试一次）处理 OAuth2 认证；USPS 走 XML API（simplexml）。测试全部用 FakeHttpClient（PSR-18 mock）+ fixtures。

**Tech Stack:** PHP 8.2+（readonly class）、PSR-18（Guzzle 7）、PHPUnit 10、psr/http-message、psr/http-client、guzzlehttp/psr7。

**已固化模板加固（所有适配器必须保留）：**
1. `json_decode` 后必须 `is_array` 防护，失败抛 `LogisticsException('[XX] 响应解析失败')`（strict_types 下防止 TypeError）
2. STATUS_MAP 中文关键词匹配，**'异常'/'退回' 必须排在 '签收' 之前**（防止 "签收异常" 被误判为 DELIVERED）
3. 轨迹按升序排序假设 + 注释说明

---

## Task 1: 韵达 Yd 适配器

**Files:**
- Create: `src/Carriers/Domestic/Yd.php`
- Test: `tests/Carriers/Domestic/YdTest.php`
- Create: `tests/fixtures/yd/trace.json`、`tests/fixtures/yd/empty.json`、`tests/fixtures/yd/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/Domestic/YdTest.php`（仿 YtoTest 结构，注意 STATUS_MAP 关键词顺序）:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\Domestic;

use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class YdTest extends TestCase
{
    private const CONFIG = ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret'];

    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            $query = [];
            parse_str((string) $request->getUri()->getQuery(), $query);
            $expected = md5('app_key' . self::CONFIG['app_key'] . 'timestamp' . $query['timestamp'] . self::CONFIG['app_secret']);
            self::assertSame($expected, $query['sign']);
            self::assertSame('json', json_decode((string) $request->getBody(), true)['format']);
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Yd(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/yd/trace.json')));
        $tracking = $carrier->queryTrack('YT1234567890');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('快件已签收', $tracking->latestDescription);
        self::assertSame('杭州市', $tracking->events[0]->location);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Yd(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/yd/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('YT1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $carrier = new Yd(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/yd/error.json')));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('YT1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Yd(self::CONFIG, new FakeHttpClient(fn () => FakeHttpClient::json('"boom"')));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('YT1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $carrier = new Yd(self::CONFIG, new FakeHttpClient());
        foreach (['createOrder', 'createLabel', 'subscribe'] as $method) {
            try {
                $carrier->{$method}(...($method === 'createOrder' ? [$this->createMock(\GlobalLogistics\Models\OrderRequest::class)] : ['https://example.com/cb']));
                self::fail("$method should throw");
            } catch (LogisticsException $e) {
                self::assertStringContainsString('待实现', $e->getMessage());
            }
        }
    }
}
```

注意：`createOrder` 参数为 `OrderRequest`，其它为字符串——用上面三目写法。若 OrderRequest mock 构造复杂，可在测试中直接用真实 `new OrderRequest(...)`（按构造函数参数）。

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/YdTest.php`
Expected: FAIL（Yd 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Yd.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
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

final class Yd implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.yundaex.com/erpApi/traceQuery';

    // 关键词顺序敏感：'异常'/'退回' 必须在 '签收' 之前
    private const STATUS_MAP = [
        '签收' => TrackStatus::Delivered,
        '退回' => TrackStatus::Returned,
        '异常' => TrackStatus::Exception,
        '派送' => TrackStatus::OutForDelivery,
        '运输' => TrackStatus::InTransit,
        '揽收' => TrackStatus::Pending,
    ];

    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $timestamp = (string) time();
        $sign = md5('app_key' . $this->config['app_key'] . 'timestamp' . $timestamp . $this->config['app_secret']);

        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT . '?app_key=' . $this->config['app_key'] . '&timestamp=' . $timestamp . '&sign=' . $sign,
            ['Content-Type' => 'application/json'],
            json_encode(['trackingNumber' => $trackingNo, 'format' => 'json']),
        );

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[YD] 响应解析失败');
        }

        $this->throwForApiError($result);

        $traces = $result['data'] ?? [];
        if (!is_array($traces) || count($traces) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $events[] = new TrackingEvent(
                time: $this->mapEventTime($trace['trackTime'] ?? null),
                location: $trace['stationName'] ?? null,
                description: $trace['trackDesc'] ?? null,
                status: $this->mapStatus($trace['trackDesc'] ?? ''),
            );
        }

        // 韵达返回按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'yd',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        return $dt === false ? null : $dt;
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($desc, $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function throwForApiError(array $result): void
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === '1' || $status === '') {
            return;
        }
        $message = (string) ($result['msg'] ?? $result['message'] ?? '');
        if (in_array($status, ['4001', '4002', '4003'], true)) {
            throw new AuthException('韵达认证失败：' . $message);
        }
        throw new LogisticsException('韵达接口错误：' . $message);
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('韵达在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('韵达电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('韵达轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/YdTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Yd.php tests/Carriers/Domestic/YdTest.php tests/fixtures/yd/
git commit -m "feat: add Yunda (yd) domestic carrier adapter"
```

## Task 2: 申通 Sto 适配器

**Files:**
- Create: `src/Carriers/Domestic/Sto.php`
- Test: `tests/Carriers/Domestic/StoTest.php`
- Create: `tests/fixtures/sto/trace.json`、`tests/fixtures/sto/empty.json`、`tests/fixtures/sto/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/Domestic/StoTest.php`（响应结构 `{data:{traces:[{scanTime,scanDesc,scanType,location}]}}`，无签名——纯 POST body）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\Domestic;

use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class StoTest extends TestCase
{
    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            $payload = json_decode((string) $request->getBody(), true);
            self::assertSame('773001234567890', $payload['waybillNo'] ?? null);
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Sto(['customer_id' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/sto/trace.json')));
        $tracking = $carrier->queryTrack('773001234567890');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('已签收', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Sto(['customer_id' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/sto/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('773001234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $carrier = new Sto(['customer_id' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/sto/error.json')));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('773001234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Sto(['customer_id' => 'test'], new FakeHttpClient(fn () => FakeHttpClient::json('"boom"')));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('773001234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $carrier = new Sto(['customer_id' => 'test'], new FakeHttpClient());
        $this->expectException(LogisticsException::class);
        $carrier->createLabel($this->createMock(\GlobalLogistics\Models\Order::class));
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/StoTest.php`
Expected: FAIL（Sto 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Sto.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
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

final class Sto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.sto.cn/erpapi/traceQuery';

    // 关键词顺序敏感：'异常'/'退回' 必须在 '签收' 之前
    private const STATUS_MAP = [
        '签收' => TrackStatus::Delivered,
        '退回' => TrackStatus::Returned,
        '异常' => TrackStatus::Exception,
        '派送' => TrackStatus::OutForDelivery,
        '运输' => TrackStatus::InTransit,
        '揽收' => TrackStatus::Pending,
    ];

    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT,
            ['Content-Type' => 'application/json'],
            json_encode(['waybillNo' => $trackingNo]),
        );

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[STO] 响应解析失败');
        }

        $this->throwForApiError($result);

        $traces = $result['data']['traces'] ?? null;
        if (!is_array($traces) || count($traces) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $events[] = new TrackingEvent(
                time: $this->mapEventTime($trace['scanTime'] ?? null),
                location: $trace['location'] ?? null,
                description: $trace['scanDesc'] ?? null,
                status: $this->mapStatus($trace['scanDesc'] ?? ''),
            );
        }

        // 申通返回按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'sto',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        return $dt === false ? null : $dt;
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($desc, $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function throwForApiError(array $result): void
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === '1' || $status === '') {
            return;
        }
        $message = (string) ($result['msg'] ?? $result['message'] ?? '');
        if (in_array($status, ['4001', '4002', '4003'], true)) {
            throw new AuthException('申通认证失败：' . $message);
        }
        throw new LogisticsException('申通接口错误：' . $message);
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('申通在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('申通电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('申通轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/StoTest.php`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Sto.php tests/Carriers/Domestic/StoTest.php tests/fixtures/sto/
git commit -m "feat: add Shentong (sto) domestic carrier adapter"
```

## Task 3: 京东 Jd 适配器

**Files:**
- Create: `src/Carriers/Domestic/Jd.php`
- Test: `tests/Carriers/Domestic/JdTest.php`
- Create: `tests/fixtures/jd/trace.json`、`tests/fixtures/jd/empty.json`、`tests/fixtures/jd/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/Domestic/JdTest.php`（响应 `{code:'0', data:{traces:[{operateTime,operateDesc,stationName}]}}`，body `{orderId}`）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\Domestic;

use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class JdTest extends TestCase
{
    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            $payload = json_decode((string) $request->getBody(), true);
            self::assertSame('JD1234567890', $payload['orderId'] ?? null);
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Jd(['app_key' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/jd/trace.json')));
        $tracking = $carrier->queryTrack('JD1234567890');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('已签收', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Jd(['app_key' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/jd/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('JD1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $carrier = new Jd(['app_key' => 'test'], self::client(file_get_contents(__DIR__ . '/../../fixtures/jd/error.json')));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('JD1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Jd(['app_key' => 'test'], new FakeHttpClient(fn () => FakeHttpClient::json('"boom"')));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('JD1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $carrier = new Jd(['app_key' => 'test'], new FakeHttpClient());
        $this->expectException(LogisticsException::class);
        $carrier->subscribe('https://example.com/cb');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/JdTest.php`
Expected: FAIL（Jd 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Jd.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
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

final class Jd implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.jdl.com/api/traceQuery';

    // 关键词顺序敏感：'异常'/'退回' 必须在 '签收' 之前
    private const STATUS_MAP = [
        '签收' => TrackStatus::Delivered,
        '退回' => TrackStatus::Returned,
        '异常' => TrackStatus::Exception,
        '派送' => TrackStatus::OutForDelivery,
        '运输' => TrackStatus::InTransit,
        '揽收' => TrackStatus::Pending,
    ];

    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT,
            ['Content-Type' => 'application/json'],
            json_encode(['orderId' => $trackingNo]),
        );

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[JD] 响应解析失败');
        }

        $this->throwForApiError($result);

        $traces = $result['data']['traces'] ?? null;
        if (!is_array($traces) || count($traces) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $events[] = new TrackingEvent(
                time: $this->mapEventTime($trace['operateTime'] ?? null),
                location: $trace['stationName'] ?? null,
                description: $trace['operateDesc'] ?? null,
                status: $this->mapStatus($trace['operateDesc'] ?? ''),
            );
        }

        // 京东返回按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'jd',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        return $dt === false ? null : $dt;
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($desc, $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function throwForApiError(array $result): void
    {
        $code = (string) ($result['code'] ?? '0');
        if ($code === '0' || $code === '') {
            return;
        }
        $message = (string) ($result['msg'] ?? $result['message'] ?? '');
        if (in_array($code, ['401', '403'], true)) {
            throw new AuthException('京东认证失败：' . $message);
        }
        throw new LogisticsException('京东接口错误：' . $message);
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('京东在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('京东电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('京东轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/JdTest.php`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Jd.php tests/Carriers/Domestic/JdTest.php tests/fixtures/jd/
git commit -m "feat: add JD Logistics (jd) domestic carrier adapter"
```

## Task 4: EMS 适配器

**Files:**
- Create: `src/Carriers/Domestic/Ems.php`
- Test: `tests/Carriers/Domestic/EmsTest.php`
- Create: `tests/fixtures/ems/trace.json`、`tests/fixtures/ems/empty.json`、`tests/fixtures/ems/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/Domestic/EmsTest.php`（响应 `{code:'0', data:{traces:[{opTime,opDesc,opOrg}]}}`，query `app_id`，body `{billNo}`；**状态关键词：'收寄'→PENDING**）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\Domestic;

use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class EmsTest extends TestCase
{
    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            $query = [];
            parse_str((string) $request->getUri()->getQuery(), $query);
            self::assertSame('test-app-id', $query['app_id'] ?? null);
            $payload = json_decode((string) $request->getBody(), true);
            self::assertSame('EA123456789CN', $payload['billNo'] ?? null);
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Ems(['app_id' => 'test-app-id'], self::client(file_get_contents(__DIR__ . '/../../fixtures/ems/trace.json')));
        $tracking = $carrier->queryTrack('EA123456789CN');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('妥投', $tracking->latestDescription);
    }

    public function testQueryTrackMapsPendingFromShouji(): void
    {
        $carrier = new Ems(['app_id' => 'test-app-id'], self::client(json_encode([
            'code' => '0',
            'data' => ['traces' => [
                ['opTime' => '2026-08-01 09:00:00', 'opDesc' => '收寄', 'opOrg' => '北京'],
            ]],
        ])));
        $tracking = $carrier->queryTrack('EA123456789CN');

        self::assertSame(TrackStatus::Pending, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Ems(['app_id' => 'test-app-id'], self::client(file_get_contents(__DIR__ . '/../../fixtures/ems/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('EA123456789CN');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $carrier = new Ems(['app_id' => 'test-app-id'], self::client(file_get_contents(__DIR__ . '/../../fixtures/ems/error.json')));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('EA123456789CN');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Ems(['app_id' => 'test-app-id'], new FakeHttpClient(fn () => FakeHttpClient::json('"boom"')));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('EA123456789CN');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/EmsTest.php`
Expected: FAIL（Ems 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Ems.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\Domestic;

use GlobalLogistics\CarrierInterface;
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

final class Ems implements CarrierInterface
{
    private const ENDPOINT = 'https://api.ems.com.cn/tracking/query';

    // 关键词顺序敏感：'异常'/'退回' 必须在 '签收' 之前；'收寄' 为揽收前置状态
    private const STATUS_MAP = [
        '妥投' => TrackStatus::Delivered,
        '签收' => TrackStatus::Delivered,
        '退回' => TrackStatus::Returned,
        '异常' => TrackStatus::Exception,
        '派送' => TrackStatus::OutForDelivery,
        '运输' => TrackStatus::InTransit,
        '收寄' => TrackStatus::Pending,
    ];

    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT . '?app_id=' . $this->config['app_id'],
            ['Content-Type' => 'application/json'],
            json_encode(['billNo' => $trackingNo]),
        );

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[EMS] 响应解析失败');
        }

        $this->throwForApiError($result);

        $traces = $result['data']['traces'] ?? null;
        if (!is_array($traces) || count($traces) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $events[] = new TrackingEvent(
                time: $this->mapEventTime($trace['opTime'] ?? null),
                location: $trace['opOrg'] ?? null,
                description: $trace['opDesc'] ?? null,
                status: $this->mapStatus($trace['opDesc'] ?? ''),
            );
        }

        // EMS 返回按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'ems',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        return $dt === false ? null : $dt;
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains($desc, $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function throwForApiError(array $result): void
    {
        $code = (string) ($result['code'] ?? '0');
        if ($code === '0' || $code === '') {
            return;
        }
        $message = (string) ($result['msg'] ?? $result['message'] ?? '');
        if (in_array($code, ['1001', '1002', '1003'], true)) {
            throw new AuthException('EMS认证失败：' . $message);
        }
        throw new LogisticsException('EMS接口错误：' . $message);
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('EMS在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('EMS电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('EMS轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/Domestic/EmsTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Ems.php tests/Carriers/Domestic/EmsTest.php tests/fixtures/ems/
git commit -m "feat: add EMS (ems) domestic carrier adapter"
```

## Task 5: OAuthTokenClient（国际 OAuth 共享组件）

**Files:**
- Create: `src/Http/OAuthTokenClient.php`
- Test: `tests/Unit/OAuthTokenClientTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/OAuthTokenClientTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Tests\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class OAuthTokenClientTest extends TestCase
{
    private const TOKEN_URL = 'https://example.com/token';
    private const TOKEN_BODY = '{"access_token":"tok-123","expires_in":3600}';

    public function testFetchesTokenLazilyOnFirstRequest(): void
    {
        $tokenRequests = 0;
        $inner = new FakeHttpClient(function ($request) use (&$tokenRequests) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                $tokenRequests++;
                self::assertSame('POST', $request->getMethod());
                return FakeHttpClient::json(self::TOKEN_BODY);
            }
            self::assertSame('Bearer tok-123', $request->getHeaderLine('Authorization'));
            return FakeHttpClient::json('{}');
        });

        $client = new OAuthTokenClient($inner, self::TOKEN_URL, ['client_id' => 'cid', 'client_secret' => 'cs'], basicAuth: false);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
        $client->sendRequest(new Request('GET', 'https://example.com/track'));

        self::assertSame(1, $tokenRequests); // token 缓存，仅获取一次
    }

    public function testSendsCredentialsInFormBody(): void
    {
        $inner = new FakeHttpClient(function ($request) {
            self::assertSame(self::TOKEN_URL, (string) $request->getUri());
            $body = (string) $request->getBody();
            self::assertStringContainsString('client_id=cid', $body);
            self::assertStringContainsString('client_secret=cs', $body);
            self::assertStringContainsString('grant_type=client_credentials', $body);
            return FakeHttpClient::json(self::TOKEN_BODY);
        });

        $client = new OAuthTokenClient($inner, self::TOKEN_URL, ['client_id' => 'cid', 'client_secret' => 'cs'], basicAuth: false);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }

    public function testSendsBasicAuthHeader(): void
    {
        $inner = new FakeHttpClient(function ($request) {
            if ((string) $request->getUri() !== self::TOKEN_URL) {
                return FakeHttpClient::json('{}');
            }
            self::assertSame('Basic ' . base64_encode('cid:cs'), $request->getHeaderLine('Authorization'));
            return FakeHttpClient::json(self::TOKEN_BODY);
        });

        $client = new OAuthTokenClient($inner, self::TOKEN_URL, ['client_id' => 'cid', 'client_secret' => 'cs'], basicAuth: true);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }

    public function testRefreshesTokenOn401AndRetriesOnce(): void
    {
        $calls = 0;
        $inner = new FakeHttpClient(function ($request) use (&$calls) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                return FakeHttpClient::json('{"access_token":"tok-' . ++$calls . '","expires_in":3600}');
            }
            if ($calls === 1) {
                return FakeHttpClient::json('{"error":"unauthorized"}', 401);
            }
            self::assertSame('Bearer tok-2', $request->getHeaderLine('Authorization'));
            return FakeHttpClient::json('{"ok":true}');
        });

        $client = new OAuthTokenClient($inner, self::TOKEN_URL, ['client_id' => 'cid', 'client_secret' => 'cs'], basicAuth: false);
        $response = $client->sendRequest(new Request('GET', 'https://example.com/track'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThrowsAuthExceptionWhenTokenEndpointFails(): void
    {
        $inner = new FakeHttpClient(function ($request) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                return FakeHttpClient::json('{"error":"invalid_client"}', 400);
            }
            return FakeHttpClient::json('{}');
        });

        $client = new OAuthTokenClient($inner, self::TOKEN_URL, ['client_id' => 'cid', 'client_secret' => 'cs'], basicAuth: false);
        $this->expectException(AuthException::class);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Unit/OAuthTokenClientTest.php`
Expected: FAIL（OAuthTokenClient 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Http/OAuthTokenClient.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Http;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\NetworkException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 decorator：lazy 获取 OAuth2 token、实例级缓存、
 * 401 时刷新 token 并重试一次。basicAuth=false 时凭据走 form body，
 * true 时走 Basic Authorization header。
 */
final class OAuthTokenClient implements ClientInterface
{
    private ?string $token = null;
    private ?int $expiresAt = null;

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $tokenUrl,
        private readonly array $credentials,
        private readonly bool $basicAuth = false,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->withAuthorization($request, $this->token());
        $response = $this->inner->sendRequest($request);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        // token 失效：强制刷新并重试一次
        $this->token = null;
        $this->expiresAt = null;
        $retry = $this->withAuthorization($request, $this->token());
        return $this->inner->sendRequest($retry);
    }

    private function token(): string
    {
        if ($this->token !== null && ($this->expiresAt === null || time() < $this->expiresAt)) {
            return $this->token;
        }

        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
        ]);

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($this->basicAuth) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                $this->credentials['client_id'] . ':' . $this->credentials['client_secret'],
            );
        }

        $request = new Request('POST', $this->tokenUrl, $headers, $body);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new NetworkException('OAuth token 获取失败：' . $e->getMessage(), previous: $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['access_token'])) {
            throw new AuthException('OAuth token 获取失败：' . $response->getBody());
        }

        $this->token = (string) $data['access_token'];
        $this->expiresAt = isset($data['expires_in']) && is_numeric($data['expires_in'])
            ? time() + (int) $data['expires_in'] - 60
            : null;

        return $this->token;
    }

    private function withAuthorization(RequestInterface $request, string $token): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer ' . $token);
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Unit/OAuthTokenClientTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Http/OAuthTokenClient.php tests/Unit/OAuthTokenClientTest.php
git commit -m "feat: add OAuthTokenClient PSR-18 decorator for international carriers"
```

## Task 6: DHL 适配器（国际，OAuth）

**Files:**
- Create: `src/Carriers/International/Dhl.php`
- Test: `tests/Carriers/International/DhlTest.php`
- Create: `tests/fixtures/dhl/trace.json`、`tests/fixtures/dhl/empty.json`、`tests/fixtures/dhl/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/International/DhlTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\International;

use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class DhlTest extends TestCase
{
    private const CONFIG = ['client_id' => 'cid', 'client_secret' => 'cs'];

    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            if ((string) $request->getUri() === 'https://api.dhl.com/mydhlapi/auth') {
                return FakeHttpClient::json('{"access_token":"tok-dhl","expires_in":3600}');
            }
            self::assertSame('Bearer tok-dhl', $request->getHeaderLine('Authorization'));
            self::assertStringContainsString('trackingNumber=DHL1234567890', (string) $request->getUri());
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Dhl(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/dhl/trace.json')));
        $tracking = $carrier->queryTrack('DHL1234567890');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('Delivered', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Dhl(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/dhl/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('DHL1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $carrier = new Dhl(self::CONFIG, self::client('{"title":"Invalid token","status":401}', 401));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('DHL1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Dhl(self::CONFIG, new FakeHttpClient(function ($request) {
            if ((string) $request->getUri() === 'https://api.dhl.com/mydhlapi/auth') {
                return FakeHttpClient::json('{"access_token":"tok-dhl","expires_in":3600}');
            }
            return FakeHttpClient::json('"boom"');
        }));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('DHL1234567890');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/International/DhlTest.php`
Expected: FAIL（Dhl 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Dhl.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Dhl implements CarrierInterface
{
    private const ENDPOINT = 'https://api.dhl.com/mydhlapi/shipments';
    private const TOKEN_URL = 'https://api.dhl.com/mydhlapi/auth';

    private readonly ClientInterface $http;

    public function __construct(
        private readonly array $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']],
            basicAuth: false,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'GET',
            self::ENDPOINT . '?trackingNumber=' . urlencode($trackingNo),
        );

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DHL] 响应解析失败');
        }

        $shipments = $result['shipments'] ?? null;
        if (!is_array($shipments) || count($shipments) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($shipments as $shipment) {
            if (!is_array($shipment)) {
                continue;
            }
            $statusCode = (string) ($shipment['status']['statusCode'] ?? '');
            foreach (($shipment['events'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $events[] = new TrackingEvent(
                    time: $this->mapEventTime($event['timestamp'] ?? null),
                    location: $this->mapLocation($event['location'] ?? null),
                    description: $event['description'] ?? null,
                    status: $this->mapStatus((string) ($event['statusCode'] ?? '')),
                );
            }
            // DHL 返回 events 按时间升序
            if ($statusCode !== '' && $events !== []) {
                $events[array_key_last($events)] = new TrackingEvent(
                    time: $events[array_key_last($events)]->time,
                    location: $events[array_key_last($events)]->location,
                    description: $events[array_key_last($events)]->description,
                    status: $this->mapStatus($statusCode),
                );
            }
        }

        return new Tracking(
            carrierCode: 'dhl',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $raw);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw);
        }
        return $dt === false ? null : $dt;
    }

    private function mapLocation(mixed $raw): ?string
    {
        if (!is_array($raw)) {
            return null;
        }
        $parts = array_filter([
            $raw['address']['city'] ?? null,
            $raw['address']['countryCode'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
        return $parts === [] ? null : implode(', ', $parts);
    }

    // DHL statusCode 官方枚举
    private function mapStatus(string $code): TrackStatus
    {
        return match ($code) {
            'delivered' => TrackStatus::Delivered,
            'pre-transit' => TrackStatus::Pending,
            'transit' => TrackStatus::InTransit,
            'failure', 'exception' => TrackStatus::Exception,
            'returned' => TrackStatus::Returned,
            default => TrackStatus::Unknown,
        };
    }

    private function assertHttpOk($response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException('DHL 认证失败：HTTP ' . $status);
        }
        if ($status >= 400) {
            throw new LogisticsException('DHL 接口错误：HTTP ' . $status);
        }
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('DHL在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('DHL电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('DHL轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/International/DhlTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Dhl.php tests/Carriers/International/DhlTest.php tests/fixtures/dhl/
git commit -m "feat: add DHL international carrier adapter with OAuth"
```

## Task 7: FedEx 适配器（国际，OAuth）

**Files:**
- Create: `src/Carriers/International/FedEx.php`
- Test: `tests/Carriers/International/FedExTest.php`
- Create: `tests/fixtures/fedex/trace.json`、`tests/fixtures/fedex/empty.json`、`tests/fixtures/fedex/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/International/FedExTest.php`（POST body `{trackingNumberInfo:{trackingNumber},includeDetailedScans:true}`，响应 `{trackingResults:[{statusByTrack:{...},scanEvents:[...]}]}`；状态优先用 `statusByTrack.state`，事件用描述关键词 STATUS_MAP）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\International;

use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class FedExTest extends TestCase
{
    private const CONFIG = ['client_id' => 'cid', 'client_secret' => 'cs'];

    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            if ((string) $request->getUri() === 'https://apis.fedex.com/oauth/token') {
                return FakeHttpClient::json('{"access_token":"tok-fedex","expires_in":3600}');
            }
            self::assertSame('Bearer tok-fedex', $request->getHeaderLine('Authorization'));
            $payload = json_decode((string) $request->getBody(), true);
            self::assertSame('FEDEX1234567890', $payload['trackingNumberInfo']['trackingNumber'] ?? null);
            self::assertTrue($payload['includeDetailedScans'] ?? false);
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new FedEx(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/fedex/trace.json')));
        $tracking = $carrier->queryTrack('FEDEX1234567890');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new FedEx(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/fedex/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $carrier = new FedEx(self::CONFIG, self::client('{"error":"invalid_token"}', 401));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new FedEx(self::CONFIG, new FakeHttpClient(function ($request) {
            if ((string) $request->getUri() === 'https://apis.fedex.com/oauth/token') {
                return FakeHttpClient::json('{"access_token":"tok-fedex","expires_in":3600}');
            }
            return FakeHttpClient::json('"boom"');
        }));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('FEDEX1234567890');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/International/FedExTest.php`
Expected: FAIL（FedEx 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/FedEx.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class FedEx implements CarrierInterface
{
    private const ENDPOINT = 'https://apis.fedex.com/track/v1/trackingnumbers';
    private const TOKEN_URL = 'https://apis.fedex.com/oauth/token';

    // 关键词顺序敏感：'EXCEPTION'/'RETURN' 必须在 'DELIVERED' 之前
    private const STATUS_MAP = [
        'DELIVERED' => TrackStatus::Delivered,
        'RETURN' => TrackStatus::Returned,
        'EXCEPTION' => TrackStatus::Exception,
        'OUT FOR DELIVERY' => TrackStatus::OutForDelivery,
        'IN TRANSIT' => TrackStatus::InTransit,
        'PICKUP' => TrackStatus::Pending,
    ];

    private readonly ClientInterface $http;

    public function __construct(
        private readonly array $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']],
            basicAuth: false,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'POST',
            self::ENDPOINT,
            ['Content-Type' => 'application/json'],
            json_encode([
                'trackingNumberInfo' => ['trackingNumber' => $trackingNo],
                'includeDetailedScans' => true,
            ]),
        );

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[FEDEX] 响应解析失败');
        }

        $trackingResults = $result['output']['completeTrackResults'] ?? null;
        if (!is_array($trackingResults) || count($trackingResults) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        $status = TrackStatus::Unknown;
        foreach ($trackingResults as $trackingResult) {
            if (!is_array($trackingResult)) {
                continue;
            }
            $trackResult = $trackingResult['trackResults'][0] ?? null;
            if (!is_array($trackResult)) {
                continue;
            }
            $state = (string) ($trackResult['statusByTrack']['state'] ?? '');
            if ($state !== '') {
                $status = $this->mapState($state);
            }
            foreach (($trackResult['scanEvents'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $desc = (string) ($event['scanDescription'] ?? $event['statusDescription'] ?? '');
                $events[] = new TrackingEvent(
                    time: $this->mapEventTime($event['date'] ?? null, $event['time'] ?? null),
                    location: $event['scanLocation']['city'] ?? null,
                    description: $desc !== '' ? $desc : null,
                    status: $this->mapStatus($desc),
                );
            }
        }

        if ($events !== [] && $status === TrackStatus::Unknown) {
            $status = $events[array_key_last($events)]->status;
        }

        // FedEx scanEvents 按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'fedex',
            trackingNo: $trackingNo,
            status: $status,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        $raw = is_string($time) && $time !== '' ? $date . ' ' . $time : $date;
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        if ($dt === false) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        }
        return $dt === false ? null : $dt;
    }

    private function mapState(string $state): TrackStatus
    {
        return match (strtoupper($state)) {
            'DELIVERED' => TrackStatus::Delivered,
            'PICKUP' => TrackStatus::Pending,
            'OUT_FOR_DELIVERY' => TrackStatus::OutForDelivery,
            'IN_TRANSIT' => TrackStatus::InTransit,
            'EXCEPTION', 'FAILURE' => TrackStatus::Exception,
            'RETURNED' => TrackStatus::Returned,
            default => TrackStatus::Unknown,
        };
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains(strtoupper($desc), $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function assertHttpOk($response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException('FedEx 认证失败：HTTP ' . $status);
        }
        if ($status >= 400) {
            throw new LogisticsException('FedEx 接口错误：HTTP ' . $status);
        }
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('FedEx在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('FedEx电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('FedEx轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/International/FedExTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/FedEx.php tests/Carriers/International/FedExTest.php tests/fixtures/fedex/
git commit -m "feat: add FedEx international carrier adapter with OAuth"
```

## Task 8: UPS 适配器（国际，OAuth + Basic Auth）

**Files:**
- Create: `src/Carriers/International/Ups.php`
- Test: `tests/Carriers/International/UpsTest.php`
- Create: `tests/fixtures/ups/trace.json`、`tests/fixtures/ups/empty.json`、`tests/fixtures/ups/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/International/UpsTest.php`（**OAuthTokenClient basicAuth: true**；GET `https://wwwcie.ups.com/api/track/v1/details/{no}`；header `transId`/`transactionSrc`；日期格式 `'Ymd'` + `'His'` → `'YmdHis'`；状态 types：M(Manifest)/I(In Transit)/O(Out for Delivery)/D(Delivered)/X(Exception)/R(Return)）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\International;

use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class UpsTest extends TestCase
{
    private const CONFIG = ['client_id' => 'cid', 'client_secret' => 'cs'];

    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return FakeHttpClient::json('{"access_token":"tok-ups","expires_in":3600}');
            }
            self::assertSame('Bearer tok-ups', $request->getHeaderLine('Authorization'));
            self::assertNotSame('', $request->getHeaderLine('transId'));
            self::assertSame('global-logistics', $request->getHeaderLine('transactionSrc'));
            self::assertStringContainsString('/track/v1/details/1Z9999999999999999', (string) $request->getUri());
            return FakeHttpClient::json($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Ups(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/ups/trace.json')));
        $tracking = $carrier->queryTrack('1Z9999999999999999');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Ups(self::CONFIG, self::client(file_get_contents(__DIR__ . '/../../fixtures/ups/empty.json')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $carrier = new Ups(self::CONFIG, self::client('{"response":{"errors":[]}}', 401));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $carrier = new Ups(self::CONFIG, new FakeHttpClient(function ($request) {
            if (str_contains((string) $request->getUri(), '/security/v1/oauth/token')) {
                return FakeHttpClient::json('{"access_token":"tok-ups","expires_in":3600}');
            }
            return FakeHttpClient::json('"boom"');
        }));
        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $carrier->queryTrack('1Z9999999999999999');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/International/UpsTest.php`
Expected: FAIL（Ups 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Ups.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Models\Label;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Models\Tracking;
use GlobalLogistics\Models\TrackingEvent;
use GlobalLogistics\Support\TrackStatus;
use Psr\Http\Client\ClientInterface;

final class Ups implements CarrierInterface
{
    private const ENDPOINT = 'https://wwwcie.ups.com/api/track/v1/details';
    private const TOKEN_URL = 'https://wwwcie.ups.com/security/v1/oauth/token';

    private readonly ClientInterface $http;

    public function __construct(
        private readonly array $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']],
            basicAuth: true,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request(
            'GET',
            self::ENDPOINT . '/' . urlencode($trackingNo),
            [
                'transId' => bin2hex(random_bytes(8)),
                'transactionSrc' => 'global-logistics',
            ],
        );

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[UPS] 响应解析失败');
        }

        $track = $result['trackResponse']['shipment'][0]['package'][0] ?? null;
        if (!is_array($track)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($track['activity'] ?? []) as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $location = $activity['location'] ?? null;
            $events[] = new TrackingEvent(
                time: $this->mapEventTime($activity['date'] ?? null, $activity['time'] ?? null),
                location: is_array($location)
                    ? trim(($location['address']['city'] ?? '') . ' ' . ($location['address']['countryCode'] ?? '')) ?: null
                    : null,
                description: $activity['status']['description'] ?? null,
                status: $this->mapStatus((string) ($activity['status']['type'] ?? '')),
            );
        }

        // UPS activity 按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'ups',
            trackingNo: $trackingNo,
            status: $events !== [] ? $events[array_key_last($events)]->status : TrackStatus::Unknown,
            events: $events,
            latestDescription: $events !== [] ? $events[array_key_last($events)]->description : null,
            signedAt: $events !== [] ? $events[array_key_last($events)]->time : null,
        );
    }

    private function mapEventTime(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        $raw = $date . (is_string($time) && $time !== '' ? $time : '000000');
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', $raw);
        return $dt === false ? null : $dt;
    }

    // UPS status type 官方枚举：M/I/O/D/X/R
    private function mapStatus(string $type): TrackStatus
    {
        return match (strtoupper($type)) {
            'D' => TrackStatus::Delivered,
            'M' => TrackStatus::Pending,
            'I' => TrackStatus::InTransit,
            'O' => TrackStatus::OutForDelivery,
            'X' => TrackStatus::Exception,
            'R' => TrackStatus::Returned,
            default => TrackStatus::Unknown,
        };
    }

    private function assertHttpOk($response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException('UPS 认证失败：HTTP ' . $status);
        }
        if ($status >= 400) {
            throw new LogisticsException('UPS 接口错误：HTTP ' . $status);
        }
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('UPS在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('UPS电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('UPS轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/International/UpsTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Ups.php tests/Carriers/International/UpsTest.php tests/fixtures/ups/
git commit -m "feat: add UPS international carrier adapter with OAuth"
```

## Task 9: USPS 适配器（国际，XML API）

**Files:**
- Create: `src/Carriers/International/Usps.php`
- Test: `tests/Carriers/International/UspsTest.php`
- Create: `tests/fixtures/usps/trace.xml`、`tests/fixtures/usps/empty.xml`、`tests/fixtures/usps/error.xml`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/International/UspsTest.php`（GET `https://secure.shippingapis.com/ShippingAPI.dll?API=TrackV2&XML=...`，`simplexml_load_string`，TrackDetail regex `'/^([A-Za-z]+ \d{1,2}, \d{4}, \d{1,2}:\d{2} [ap]m), (.*)$/'`，格式 `'F j, Y, g:i a'`；Error 元素 code 80040B1A/80040B1C/80040B20→AuthException）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers\International;

use GlobalLogistics\Carriers\International\Usps;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class UspsTest extends TestCase
{
    private static function client(string $body, int $status = 200): FakeHttpClient
    {
        return new FakeHttpClient(function ($request) use ($body, $status) {
            self::assertSame('GET', $request->getMethod());
            $query = [];
            parse_str((string) $request->getUri()->getQuery(), $query);
            self::assertSame('TrackV2', $query['API'] ?? null);
            self::assertSame('test-user', $query['XML'] !== null ? (string) simplexml_load_string($query['XML'])->UserId : null);
            return FakeHttpClient::xml($body, $status);
        });
    }

    public function testQueryTrackParsesTrace(): void
    {
        $carrier = new Usps(['user_id' => 'test-user'], self::client(file_get_contents(__DIR__ . '/../../fixtures/usps/trace.xml')));
        $tracking = $carrier->queryTrack('9400111899223197448523');

        self::assertSame(TrackStatus::Delivered, $tracking->status);
        self::assertCount(2, $tracking->events);
        self::assertSame('Delivered', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $carrier = new Usps(['user_id' => 'test-user'], self::client(file_get_contents(__DIR__ . '/../../fixtures/usps/empty.xml')));
        $this->expectException(TrackingNotFoundException::class);
        $carrier->queryTrack('9400111899223197448523');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $carrier = new Usps(['user_id' => 'test-user'], self::client(file_get_contents(__DIR__ . '/../../fixtures/usps/error.xml')));
        $this->expectException(AuthException::class);
        $carrier->queryTrack('9400111899223197448523');
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/International/UspsTest.php`
Expected: FAIL（Usps 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Usps.php`:

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
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

final class Usps implements CarrierInterface
{
    private const ENDPOINT = 'https://secure.shippingapis.com/ShippingAPI.dll';

    // 关键词顺序敏感：'EXCEPTION'/'RETURN' 必须在 'DELIVERED' 之前
    private const STATUS_MAP = [
        'DELIVERED' => TrackStatus::Delivered,
        'RETURN' => TrackStatus::Returned,
        'EXCEPTION' => TrackStatus::Exception,
        'OUT FOR DELIVERY' => TrackStatus::OutForDelivery,
        'IN TRANSIT' => TrackStatus::InTransit,
        'ACCEPT' => TrackStatus::Pending,
    ];

    public function __construct(
        private readonly array $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<TrackFieldRequest USERID="' . htmlspecialchars($this->config['user_id'], ENT_XML1) . '">'
            . '<TrackID ID="' . htmlspecialchars($trackingNo, ENT_XML1) . '"/>'
            . '</TrackFieldRequest>';

        $request = new \GuzzleHttp\Psr7\Request(
            'GET',
            self::ENDPOINT . '?API=TrackV2&XML=' . urlencode($xml),
        );

        $response = $this->http->sendRequest($request);
        $body = (string) $response->getBody();

        $parsed = @simplexml_load_string($body);
        if ($parsed === false) {
            throw new LogisticsException('[USPS] 响应解析失败');
        }

        $this->throwForApiError($parsed, $trackingNo);

        $trackInfo = $parsed->TrackInfo ?? null;
        $details = $trackInfo->TrackDetail ?? [];
        if ($trackInfo === null || count($details) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($details as $detail) {
            $text = trim((string) $detail);
            if ($text === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z]+ \d{1,2}, \d{4}, \d{1,2}:\d{2} [ap]m), (.*)$/', $text, $m)) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('F j, Y, g:i a', $m[1]);
            $events[] = new TrackingEvent(
                time: $dt === false ? null : $dt,
                description: trim($m[2]),
                status: $this->mapStatus(trim($m[2])),
            );
        }

        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // USPS TrackDetail 按时间升序；如发现乱序，调用方应自行排序
        return new Tracking(
            carrierCode: 'usps',
            trackingNo: $trackingNo,
            status: $events[array_key_last($events)]->status,
            events: $events,
            latestDescription: $events[array_key_last($events)]->description,
            signedAt: $events[array_key_last($events)]->time,
        );
    }

    private function mapStatus(string $desc): TrackStatus
    {
        foreach (self::STATUS_MAP as $keyword => $status) {
            if (str_contains(strtoupper($desc), $keyword)) {
                return $status;
            }
        }
        return TrackStatus::Unknown;
    }

    private function throwForApiError(\SimpleXMLElement $parsed, string $trackingNo): void
    {
        $error = $parsed->Error ?? null;
        if ($error === null) {
            return;
        }
        $code = (string) $error->Number;
        $message = (string) $error->Description;
        if (in_array($code, ['80040B1A', '80040B1C', '80040B20'], true)) {
            throw new AuthException('USPS 认证失败：' . $message);
        }
        throw new LogisticsException('USPS 接口错误：' . $message);
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('USPS在线下单待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('USPS电子面单待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('USPS轨迹订阅待实现');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/International/UspsTest.php`
Expected: PASS（3 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Usps.php tests/Carriers/International/UspsTest.php tests/fixtures/usps/
git commit -m "feat: add USPS international carrier adapter with XML API"
```

## Task 10: 收口 — 检测规则、注册表、README、集成测试

**Files:**
- Modify: `src/Resources/detector-rules.php`
- Modify: `src/CarrierFactory.php`
- Modify: `README.md`
- Modify: `tests/Unit/DetectorTest.php`
- Modify: `tests/LogisticsTest.php`（如存在；否则 `tests/Unit/LogisticsTest.php`，以实际为准）

- [ ] **Step 1: 更新检测规则（顺序敏感）**

在 `src/Resources/detector-rules.php` 的 domestic 区块追加（**必须保持现有规则的相对顺序，新增规则插入到 `'/^\d{13}$/'`（zto）之前**）：

```php
'/^YD\d{8,16}$/i' => ['yd', Channel::Domestic],
'/^77\d{11}$/' => ['sto', Channel::Domestic], // 必须在纯数字规则之前
'/^JD[A-Z0-9]{8,18}$/i' => ['jd', Channel::Domestic],
'/^E[A-Z]\d{9}CN$/i' => ['ems', Channel::Domestic],
```

国际区块追加：

```php
'/^DHL\d{10,15}$/i' => ['dhl', Channel::International],
'/^FEDEX\d{10,15}$/i' => ['fedex', Channel::International],
'/^1Z[A-Z0-9]{16}$/i' => ['ups', Channel::International],
'/^94\d{16,22}$/' => ['usps', Channel::International],
'/^\d{10}$/' => ['dhl', Channel::International], // 纯 10 位数字误命中风险：DHL 纯数字单号，需用户确认
```

注释：`'/^77\d{11}$/'` 必须在 `'/^\d{13}$/'` 之前（sto 单号 77 开头 13 位数字，若 13 位规则先命中会误判为 zto）。`'/^94\d{16,22}$/'`（USPS）必须位于纯数字 13 位规则之后不会冲突（长度不同），但仍建议放国际区块末尾之前。

- [ ] **Step 2: 更新 CarrierFactory 注册表**

`src/CarrierFactory.php` 的 domestic/international map 追加：

```php
'yd' => \GlobalLogistics\Carriers\Domestic\Yd::class,
'sto' => \GlobalLogistics\Carriers\Domestic\Sto::class,
'jd' => \GlobalLogistics\Carriers\Domestic\Jd::class,
'ems' => \GlobalLogistics\Carriers\Domestic\Ems::class,
```

```php
'dhl' => \GlobalLogistics\Carriers\International\Dhl::class,
'fedex' => \GlobalLogistics\Carriers\International\FedEx::class,
'ups' => \GlobalLogistics\Carriers\International\Ups::class,
'usps' => \GlobalLogistics\Carriers\International\Usps::class,
```

（保持 `use` 导入或 FQCN，与现有风格一致）

- [ ] **Step 3: 新增 Detector 测试**

在 `tests/Unit/DetectorTest.php` 追加：

```php
public function testDetectsYunda(): void
{
    $result = $this->detector->detect('YD12345678');
    self::assertSame(Channel::Domestic, $result->channel);
    self::assertSame('yd', $result->carrierCode);
}

public function testDetectsShentongBeforePureDigitRule(): void
{
    // 77 开头 13 位数字：必须是 sto 而非 zto
    $result = $this->detector->detect('773001234567890');
    self::assertSame(Channel::Domestic, $result->channel);
    self::assertSame('sto', $result->carrierCode);
}

public function testDetectsJd(): void
{
    $result = $this->detector->detect('JD1234567890');
    self::assertSame(Channel::Domestic, $result->channel);
    self::assertSame('jd', $result->carrierCode);
}

public function testDetectsEms(): void
{
    $result = $this->detector->detect('EA123456789CN');
    self::assertSame(Channel::Domestic, $result->channel);
    self::assertSame('ems', $result->carrierCode);
}

public function testDetectsDhl(): void
{
    $result = $this->detector->detect('DHL1234567890');
    self::assertSame(Channel::International, $result->channel);
    self::assertSame('dhl', $result->carrierCode);
}

public function testDetectsFedEx(): void
{
    $result = $this->detector->detect('FEDEX1234567890');
    self::assertSame(Channel::International, $result->channel);
    self::assertSame('fedex', $result->carrierCode);
}

public function testDetectsUps(): void
{
    $result = $this->detector->detect('1Z9999999999999999');
    self::assertSame(Channel::International, $result->channel);
    self::assertSame('ups', $result->carrierCode);
}

public function testDetectsUsps(): void
{
    $result = $this->detector->detect('9400111899223197448523');
    self::assertSame(Channel::International, $result->channel);
    self::assertSame('usps', $result->carrierCode);
}
```

（channel import：`use GlobalLogistics\Channel;`，detector 属性名以现有测试为准）

- [ ] **Step 4: 新增 Logistics 集成测试**

`tests/LogisticsTest.php`（或现有位置）追加：

```php
public function testTrackRoutesToInternationalChannel(): void
{
    Logistics::configure([
        'dhl' => ['client_id' => 'cid', 'client_secret' => 'cs'],
    ]);
    Logistics::useClient(new FakeHttpClient(function ($request) {
        if (str_contains((string) $request->getUri(), '/auth')) {
            return FakeHttpClient::json('{"access_token":"tok","expires_in":3600}');
        }
        return FakeHttpClient::json(file_get_contents(__DIR__ . '/fixtures/dhl/trace.json'));
    }));

    $tracking = Logistics::track('DHL1234567890');

    self::assertSame('dhl', $tracking->carrierCode);
    self::assertSame(TrackStatus::Delivered, $tracking->status);
}
```

（若 Logistics 静态状态测试后需要重置，参考现有测试模式：`Logistics::reset()` 或重新 configure 空数组，以实际实现为准）

- [ ] **Step 5: 更新 README**

在 README 已接入承运商段落更新：

```markdown
## 已接入承运商

- 国内：顺丰(sf)、中通(zto)、圆通(yto)、极兔(jt)、韵达(yd)、申通(sto)、京东(jd)、EMS(ems) —— 轨迹查询可用；下单/面单/订阅按承运商开放情况接入中
- 国际：DHL(dhl)、FedEx(fedex)、UPS(ups)、USPS(usps) —— 轨迹查询可用（OAuth2 认证，config 需 client_id/client_secret，USPS 需 user_id）；下单/面单/订阅按承运商开放情况接入中
```

快速开始 config 示例追加国际密钥：

```php
Logistics::configure([
    'sf' => ['partner_id' => '...', 'checkword' => '...'],
    'zto' => ['company_id' => '...', 'secret' => '...'],
    'yto' => ['app_key' => '...', 'app_secret' => '...'],
    'jt' => ['api_key' => '...', 'secret' => '...'],
    'dhl' => ['client_id' => '...', 'client_secret' => '...'],
    'fedex' => ['client_id' => '...', 'client_secret' => '...'],
    'ups' => ['client_id' => '...', 'client_secret' => '...'],
    'usps' => ['user_id' => '...'],
]);
```

并取消国际示例注释：

```php
// 显式指定
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');
```

- [ ] **Step 6: 全量测试 + 提交**

Run: `vendor/bin/phpunit`
Expected: ALL PASS（现有 39 + 新增 ≈ 65+ tests）

```bash
git add src/Resources/detector-rules.php src/CarrierFactory.php README.md tests/
git commit -m "feat: register domestic-b and international-a carriers, update detector rules"
```

---

## 执行纪律

- 每任务必须 TDD 顺序执行：先写失败测试 → 确认失败 → 最小实现 → 确认通过 → 提交
- **模型选择**：Task 1-4、9-10（国内复制模式/USPS XML）用 haiku；Task 5（OAuthTokenClient 新组件）、6-8（国际 OAuth 模式）用 sonnet；所有 spec/quality 审查用 sonnet
- 保持既有模板加固：is_array 防护、'异常'/'退回' 在 '签收' 之前、升序注释
- 每任务完成后两阶段审查（spec 合规 → 代码质量），审查发现问题由原 implementer 修复后复审
- 全部完成后：整体代码审查 → finishing-a-development-branch（本地合并回 main，用户既定偏好）
