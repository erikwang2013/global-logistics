# Wave 1: 国内 B（韵达/申通/京东/EMS）+ 国际 A（DHL/FedEx/UPS/USPS）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为 global-logistics composer 包新增 8 家承运商适配器：国内 B（韵达 yd、申通 sto、京东 jd、EMS ems）+ 国际 A（DHL、FedEx、UPS、USPS），含 OAuth 令牌客户端（OAuthTokenClient）、检测规则合并、注册表、README 更新，全部 TDD 且无需真实密钥即可测试。

**Architecture:** 沿用 Wave 0 固化模式——每适配器 = ENDPOINT const + STATUS_MAP + mapEvent + throwForApiError + createOrder/createLabel/subscribe 抛待实现异常。**适配器构造器为 `__construct(Config $config, ClientInterface $http)`**（`Config` 对象 + `$this->config->get('xx.key')` 取密钥，`readonly` 提升属性）。国际适配器通过共享的 `OAuthTokenClient`（PSR-18 decorator，lazy token 获取 + 401 刷新重试一次，构造器内包装、用非提升 `private readonly ClientInterface $http` 属性保持两参构造签名）处理 OAuth2 认证；USPS 走 XML API（simplexml）。测试全部用 `FakeHttpClient`（PSR-18 mock，`$handler` 公开属性）+ fixtures。

**Tech Stack:** PHP 8.2+（readonly class）、PSR-18（Guzzle 7）、PHPUnit 10、psr/http-message、psr/http-client、guzzlehttp/psr7。

## 代码库既定模式（复制模板前先核对，Yto 是权威模板）

- 枚举：`TrackStatus::PENDING / IN_TRANSIT / OUT_FOR_DELIVERY / DELIVERED / EXCEPTION / RETURNED / UNKNOWN`（UPPER_SNAKE）
- `Tracking` 构造：`carrierCode, trackingNo, status, events=[], deliveredAt=null, estimatedDeliveryAt=null, latestDescription='', rawStatus='', raw=[]`（`deliveredAt` 仅当末条事件为 DELIVERED 时设置，否则 null）
- `TrackingEvent` 构造：`occurredAt (?\DateTimeImmutable), location (string), description (string), status, raw=[]`（**location/description 非 nullable**）
- 异常消息风格：`'[XX] 响应解析失败'`、`'XX createOrder 待实现'`、`sprintf('[XX %s] %s', $code, $message)`；`throwForApiError(string $code, string $message): never`
- 状态关键词匹配：`foreach (self::STATUS_MAP as $keyword => $mapped) { if (str_contains($desc, $keyword)) { $status = $mapped; break; } }`——**'异常'/'退回' 必须在 '签收' 之前**（防止 "签收异常" 误判 DELIVERED）
- 轨迹按时间升序返回，末条为最新——每个适配器保留此注释
- 测试：`tests/Carriers/{X}Test.php`（扁平目录），`use GlobalLogistics\Tests\Support\FakeHttpClient;`，`$http = new FakeHttpClient(); $http->handler = fn (Request $request) => new Response(200, [...], $body);`，适配器用 `new Yd(new Config(['yd' => [...]]), $http)`，断言用 `$this->assertSame(...)`
- fixture：`tests/fixtures/{carrier}/track.json`（参照 `tests/fixtures/yto/track.json`），error fixture 用 `error.json`，空轨迹用 `empty.json`
- **Order/OrderRequest/其他 final readonly 类不可 `createMock`**——不支持的方法测试用真实对象：`new OrderRequest(['name'=>'s'], ['name'=>'r'])`、`new Order('SF1234567890')`

---

## Task 1: 韵达 Yd 适配器

**Files:**
- Create: `src/Carriers/Domestic/Yd.php`
- Test: `tests/Carriers/YdTest.php`
- Create: `tests/fixtures/yd/track.json`、`tests/fixtures/yd/empty.json`、`tests/fixtures/yd/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/YdTest.php`（仿 YtoTest 结构）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Models\Order;
use GlobalLogistics\Models\OrderRequest;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class YdTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            assert($query['app_key'] === 'test-app-key');
            assert($query['sign'] === md5('app_key' . 'test-app-key' . 'timestamp' . $query['timestamp'] . 'test-app-secret'));
            $body = json_decode((string) $request->getBody(), true);
            assert($body['trackingNumber'] === 'YT1234567890');
            assert($body['format'] === 'json');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/yd/track.json'));
        };

        $adapter = new Yd(
            new Config(['yd' => ['app_key' => 'test-app-key', 'app_secret' => 'test-app-secret']]),
            $http,
        );

        $tracking = $adapter->queryTrack('YT1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('杭州市', $tracking->events[0]->location);
        $this->assertSame('快件已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd/empty.json'));

        $adapter = new Yd(new Config(['yd' => ['app_key' => 'k', 'app_secret' => 's']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('YT1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/yd/error.json'));

        $adapter = new Yd(new Config(['yd' => ['app_key' => 'k', 'app_secret' => 's']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('YT1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Yd(new Config(['yd' => ['app_key' => 'k', 'app_secret' => 's']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('YT1234567890');
    }

    public function testUnsupportedMethodsThrow(): void
    {
        $adapter = new Yd(new Config(['yd' => ['app_key' => 'k', 'app_secret' => 's']]), new FakeHttpClient());

        try {
            $adapter->createOrder(new OrderRequest(['name' => 's'], ['name' => 'r']));
            $this->fail('createOrder should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->createLabel(new Order('SF1234567890'));
            $this->fail('createLabel should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }

        try {
            $adapter->subscribe('https://example.com/cb');
            $this->fail('subscribe should throw');
        } catch (LogisticsException $e) {
            $this->assertStringContainsString('待实现', $e->getMessage());
        }
    }
}
```

fixture `tests/fixtures/yd/track.json`：

```json
{
    "status": "1",
    "message": "成功",
    "data": [
        {"trackTime": "2026-08-14 10:00:00", "stationName": "上海市", "trackDesc": "快件已到达【上海转运中心】"},
        {"trackTime": "2026-08-15 18:30:00", "stationName": "杭州市", "trackDesc": "快件已签收"}
    ]
}
```

fixture `tests/fixtures/yd/empty.json`：`{"status": "1", "data": []}`
fixture `tests/fixtures/yd/error.json`：`{"status": "4001", "msg": "认证失败"}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/YdTest.php`
Expected: FAIL（Yd 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Yd.php`（严格仿 Yto 结构；若 Order 构造参数与上面假设不符，按 `src/Models/Order.php` 实际签名调整）：

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

final class Yd implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.yundaex.com/erpApi/traceQuery';

    /** 韵达状态关键词 => 统一状态（以 trackDesc 内容匹配） */
    private const STATUS_MAP = [
        '揽收' => TrackStatus::PENDING,
        '运输' => TrackStatus::IN_TRANSIT,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
        '签收' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appKey = (string) $this->config->get('yd.app_key');
        $secret = (string) $this->config->get('yd.app_secret');
        $timestamp = (string) time();
        $sign = md5('app_key' . $appKey . 'timestamp' . $timestamp . $secret);

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query([
            'app_key' => $appKey,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]), [
            'Content-Type' => 'application/json',
        ], json_encode([
            'trackingNumber' => $trackingNo,
            'format' => 'json',
        ]));

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[YD] 响应解析失败');
        }

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['msg'] ?? $result['message'] ?? ''));
        }

        $traces = $result['data'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'yd',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['trackDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('YD createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('YD createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('YD subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['trackTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['trackDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['stationName'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['4001', '4002', '4003'], true)) {
            throw new AuthException(sprintf('[YD %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[YD %s] %s', $code, $message));
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/YdTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Yd.php tests/Carriers/YdTest.php tests/fixtures/yd/
git commit -m "feat: add Yunda (yd) domestic carrier adapter"
```

## Task 2: 申通 Sto 适配器

**Files:**
- Create: `src/Carriers/Domestic/Sto.php`
- Test: `tests/Carriers/StoTest.php`
- Create: `tests/fixtures/sto/track.json`、`tests/fixtures/sto/empty.json`、`tests/fixtures/sto/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/StoTest.php`（响应 `{status, data:{traces:[{scanTime,scanDesc,location}]}}`，POST body `{waybillNo}`，无签名）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class StoTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert($body['waybillNo'] === '773001234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/sto/track.json'));
        };

        $adapter = new Sto(new Config(['sto' => ['customer_id' => 'test']]), $http);

        $tracking = $adapter->queryTrack('773001234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('已签收', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sto/empty.json'));

        $adapter = new Sto(new Config(['sto' => ['customer_id' => 'test']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('773001234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/sto/error.json'));

        $adapter = new Sto(new Config(['sto' => ['customer_id' => 'test']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('773001234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Sto(new Config(['sto' => ['customer_id' => 'test']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('773001234567890');
    }

    public function testExceptionKeywordWinsOverDelivered(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'status' => '1',
                'data' => ['traces' => [
                    ['scanTime' => '2026-08-15 18:30:00', 'scanDesc' => '签收异常-收件人拒收', 'location' => '杭州市'],
                ]],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Sto(new Config(['sto' => ['customer_id' => 'test']]), $http);

        $tracking = $adapter->queryTrack('773001234567890');

        $this->assertSame(TrackStatus::EXCEPTION, $tracking->status);
    }
}
```

fixture `tests/fixtures/sto/track.json`：

```json
{
    "status": "1",
    "data": {
        "traces": [
            {"scanTime": "2026-08-14 10:00:00", "scanDesc": "快件已到达【上海转运中心】", "location": "上海市"},
            {"scanTime": "2026-08-15 18:30:00", "scanDesc": "已签收", "location": "杭州市"}
        ]
    }
}
```

fixture `tests/fixtures/sto/empty.json`：`{"status": "1", "data": {"traces": []}}`
fixture `tests/fixtures/sto/error.json`：`{"status": "4001", "msg": "认证失败"}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/StoTest.php`
Expected: FAIL（Sto 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Sto.php`（结构与 Yd 相同，差异：ENDPOINT `https://openapi.sto.cn/erpapi/traceQuery`、config 键 `sto.customer_id`、body `{waybillNo}`、traces 取 `$result['data']['traces']`、字段 `scanTime/scanDesc/location`、前缀 `[STO]`/`STO`）：

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

final class Sto implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.sto.cn/erpapi/traceQuery';

    /** 申通状态关键词 => 统一状态（以 scanDesc 内容匹配） */
    private const STATUS_MAP = [
        '揽收' => TrackStatus::PENDING,
        '运输' => TrackStatus::IN_TRANSIT,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
        '签收' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], json_encode(['waybillNo' => $trackingNo]));

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[STO] 响应解析失败');
        }

        $status = (string) ($result['status'] ?? '');
        if ($status !== '1') {
            $this->throwForApiError($status, (string) ($result['msg'] ?? $result['message'] ?? ''));
        }

        $traces = $result['data']['traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'sto',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['scanDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('STO createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('STO createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('STO subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['scanTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['scanDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['location'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['4001', '4002', '4003'], true)) {
            throw new AuthException(sprintf('[STO %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[STO %s] %s', $code, $message));
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/StoTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Sto.php tests/Carriers/StoTest.php tests/fixtures/sto/
git commit -m "feat: add Shentong (sto) domestic carrier adapter"
```

## Task 3: 京东 Jd 适配器

**Files:**
- Create: `src/Carriers/Domestic/Jd.php`
- Test: `tests/Carriers/JdTest.php`
- Create: `tests/fixtures/jd/track.json`、`tests/fixtures/jd/empty.json`、`tests/fixtures/jd/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/JdTest.php`（响应 `{code:'0', data:{traces:[{operateTime,operateDesc,stationName}]}}`，body `{orderId}`，成功判定 `code === '0'`；错误码 401/403 → AuthException）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class JdTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $body = json_decode((string) $request->getBody(), true);
            assert($body['orderId'] === 'JD1234567890');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/jd/track.json'));
        };

        $adapter = new Jd(new Config(['jd' => ['app_key' => 'test']]), $http);

        $tracking = $adapter->queryTrack('JD1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('已签收', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jd/empty.json'));

        $adapter = new Jd(new Config(['jd' => ['app_key' => 'test']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('JD1234567890');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/jd/error.json'));

        $adapter = new Jd(new Config(['jd' => ['app_key' => 'test']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('JD1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Jd(new Config(['jd' => ['app_key' => 'test']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('JD1234567890');
    }
}
```

fixture `tests/fixtures/jd/track.json`：

```json
{
    "code": "0",
    "data": {
        "traces": [
            {"operateTime": "2026-08-14 10:00:00", "operateDesc": "快件已到达【上海转运中心】", "stationName": "上海市"},
            {"operateTime": "2026-08-15 18:30:00", "operateDesc": "已签收", "stationName": "杭州市"}
        ]
    }
}
```

fixture `tests/fixtures/jd/empty.json`：`{"code": "0", "data": {"traces": []}}`
fixture `tests/fixtures/jd/error.json`：`{"code": "401", "msg": "认证失败"}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/JdTest.php`
Expected: FAIL（Jd 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Jd.php`（结构与 Yd 相同，差异：ENDPOINT `https://openapi.jdl.com/api/traceQuery`、body `{orderId}`、traces 取 `$result['data']['traces']`、字段 `operateTime/operateDesc/stationName`、成功判定 `code === '0'`、错误码 401/403 → AuthException、前缀 `[JD]`/`JD`）：

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

final class Jd implements CarrierInterface
{
    private const ENDPOINT = 'https://openapi.jdl.com/api/traceQuery';

    /** 京东状态关键词 => 统一状态（以 operateDesc 内容匹配） */
    private const STATUS_MAP = [
        '揽收' => TrackStatus::PENDING,
        '运输' => TrackStatus::IN_TRANSIT,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
        '签收' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], json_encode(['orderId' => $trackingNo]));

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[JD] 响应解析失败');
        }

        $code = (string) ($result['code'] ?? '0');
        if ($code !== '0') {
            $this->throwForApiError($code, (string) ($result['msg'] ?? $result['message'] ?? ''));
        }

        $traces = $result['data']['traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'jd',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['operateDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('JD createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('JD createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('JD subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['operateTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['operateDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['stationName'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['401', '403'], true)) {
            throw new AuthException(sprintf('[JD %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[JD %s] %s', $code, $message));
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/JdTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Jd.php tests/Carriers/JdTest.php tests/fixtures/jd/
git commit -m "feat: add JD Logistics (jd) domestic carrier adapter"
```

## Task 4: EMS 适配器

**Files:**
- Create: `src/Carriers/Domestic/Ems.php`
- Test: `tests/Carriers/EmsTest.php`
- Create: `tests/fixtures/ems/track.json`、`tests/fixtures/ems/empty.json`、`tests/fixtures/ems/error.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/EmsTest.php`（响应 `{code:'0', data:{traces:[{opTime,opDesc,opOrg}]}}`，query `app_id`，body `{billNo}`，成功判定 `code === '0'`；**状态关键词：'妥投'/'签收'→DELIVERED，'收寄'→PENDING**）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class EmsTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            assert($query['app_id'] === 'test-app-id');
            $body = json_decode((string) $request->getBody(), true);
            assert($body['billNo'] === 'EA123456789CN');

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ems/track.json'));
        };

        $adapter = new Ems(new Config(['ems' => ['app_id' => 'test-app-id']]), $http);

        $tracking = $adapter->queryTrack('EA123456789CN');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('妥投', $tracking->latestDescription);
    }

    public function testQueryTrackMapsPendingFromShouji(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            json_encode([
                'code' => '0',
                'data' => ['traces' => [
                    ['opTime' => '2026-08-01 09:00:00', 'opDesc' => '收寄', 'opOrg' => '北京'],
                ]],
            ], JSON_UNESCAPED_UNICODE));

        $adapter = new Ems(new Config(['ems' => ['app_id' => 'test-app-id']]), $http);

        $tracking = $adapter->queryTrack('EA123456789CN');

        $this->assertSame(TrackStatus::PENDING, $tracking->status);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ems/empty.json'));

        $adapter = new Ems(new Config(['ems' => ['app_id' => 'test-app-id']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('EA123456789CN');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/ems/error.json'));

        $adapter = new Ems(new Config(['ems' => ['app_id' => 'test-app-id']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('EA123456789CN');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Ems(new Config(['ems' => ['app_id' => 'test-app-id']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('EA123456789CN');
    }
}
```

fixture `tests/fixtures/ems/track.json`：

```json
{
    "code": "0",
    "data": {
        "traces": [
            {"opTime": "2026-08-14 10:00:00", "opDesc": "已收寄", "opOrg": "上海市"},
            {"opTime": "2026-08-15 18:30:00", "opDesc": "妥投", "opOrg": "杭州市"}
        ]
    }
}
```

fixture `tests/fixtures/ems/empty.json`：`{"code": "0", "data": {"traces": []}}`
fixture `tests/fixtures/ems/error.json`：`{"code": "1001", "msg": "认证失败"}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/EmsTest.php`
Expected: FAIL（Ems 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/Domestic/Ems.php`（结构与 Jd 相同，差异：ENDPOINT `https://api.ems.com.cn/tracking/query`、query 参数 `app_id`、body `{billNo}`、字段 `opTime/opDesc/opOrg`、**STATUS_MAP 增加 '妥投'→DELIVERED 与 '收寄'→PENDING**、错误码 1001/1002/1003 → AuthException、前缀 `[EMS]`/`EMS`）：

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

final class Ems implements CarrierInterface
{
    private const ENDPOINT = 'https://api.ems.com.cn/tracking/query';

    /** EMS 状态关键词 => 统一状态（以 opDesc 内容匹配） */
    private const STATUS_MAP = [
        '收寄' => TrackStatus::PENDING,
        '运输' => TrackStatus::IN_TRANSIT,
        '派送' => TrackStatus::OUT_FOR_DELIVERY,
        '异常' => TrackStatus::EXCEPTION,
        '退回' => TrackStatus::RETURNED,
        '妥投' => TrackStatus::DELIVERED,
        '签收' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $appId = (string) $this->config->get('ems.app_id');

        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT . '?' . http_build_query([
            'app_id' => $appId,
        ]), [
            'Content-Type' => 'application/json',
        ], json_encode(['billNo' => $trackingNo]));

        $response = $this->http->sendRequest($request);
        $result = json_decode((string) $response->getBody(), true);

        if (!is_array($result)) {
            throw new LogisticsException('[EMS] 响应解析失败');
        }

        $code = (string) ($result['code'] ?? '0');
        if ($code !== '0') {
            $this->throwForApiError($code, (string) ($result['msg'] ?? $result['message'] ?? ''));
        }

        $traces = $result['data']['traces'] ?? [];
        if (!is_array($traces) || $traces === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // 轨迹按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $events = [];
        foreach ($traces as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->mapEvent($row);
        }
        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ems',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($traces[count($traces) - 1]['opDesc'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('EMS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('EMS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('EMS subscribe 待实现');
    }

    /** @param array<string, mixed> $row */
    private function mapEvent(array $row): TrackingEvent
    {
        $occurredAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($row['opTime'] ?? ''));
        if ($occurredAt === false) {
            $occurredAt = null;
        }

        $description = (string) ($row['opDesc'] ?? '');
        $status = TrackStatus::UNKNOWN;
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($description, $keyword)) {
                $status = $mapped;
                break;
            }
        }

        return new TrackingEvent(
            occurredAt: $occurredAt,
            location: (string) ($row['opOrg'] ?? ''),
            description: $description,
            status: $status,
            raw: $row,
        );
    }

    private function throwForApiError(string $code, string $message): never
    {
        if (in_array($code, ['1001', '1002', '1003'], true)) {
            throw new AuthException(sprintf('[EMS %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[EMS %s] %s', $code, $message));
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/EmsTest.php`
Expected: PASS（5 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/Domestic/Ems.php tests/Carriers/EmsTest.php tests/fixtures/ems/
git commit -m "feat: add EMS (ems) domestic carrier adapter"
```

## Task 5: OAuthTokenClient（国际 OAuth 共享组件）

**Files:**
- Create: `src/Http/OAuthTokenClient.php`
- Test: `tests/Unit/OAuthTokenClientTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/OAuthTokenClientTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Http\OAuthTokenClient;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OAuthTokenClientTest extends TestCase
{
    private const TOKEN_URL = 'https://example.com/token';
    private const CREDENTIALS = ['client_id' => 'cid', 'client_secret' => 'cs'];

    public function testFetchesTokenLazilyOnFirstRequestAndCaches(): void
    {
        $tokenRequests = 0;
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use (&$tokenRequests) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                $tokenRequests++;
                $this->assertSame('POST', $request->getMethod());
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"access_token":"tok-1","expires_in":3600}');
            }
            $this->assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
            return new Response(200, [], '{}');
        };

        $client = new OAuthTokenClient($http, self::TOKEN_URL, self::CREDENTIALS);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
        $client->sendRequest(new Request('GET', 'https://example.com/track'));

        $this->assertSame(1, $tokenRequests);
    }

    public function testSendsCredentialsInFormBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame(self::TOKEN_URL, (string) $request->getUri());
            $body = (string) $request->getBody();
            $this->assertStringContainsString('client_id=cid', $body);
            $this->assertStringContainsString('client_secret=cs', $body);
            $this->assertStringContainsString('grant_type=client_credentials', $body);
            return new Response(200, ['Content-Type' => 'application/json'],
                '{"access_token":"tok-1","expires_in":3600}');
        };

        $client = new OAuthTokenClient($http, self::TOKEN_URL, self::CREDENTIALS);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }

    public function testSendsBasicAuthHeaderWhenEnabled(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() !== self::TOKEN_URL) {
                return new Response(200, [], '{}');
            }
            $this->assertSame('Basic ' . base64_encode('cid:cs'), $request->getHeaderLine('Authorization'));
            return new Response(200, ['Content-Type' => 'application/json'],
                '{"access_token":"tok-1","expires_in":3600}');
        };

        $client = new OAuthTokenClient($http, self::TOKEN_URL, self::CREDENTIALS, basicAuth: true);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }

    public function testRefreshesTokenOn401AndRetriesOnce(): void
    {
        $calls = 0;
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) use (&$calls) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                $calls++;
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"access_token":"tok-' . $calls . '","expires_in":3600}');
            }
            if ($calls === 1) {
                return new Response(401, ['Content-Type' => 'application/json'], '{"error":"unauthorized"}');
            }
            $this->assertSame('Bearer tok-2', $request->getHeaderLine('Authorization'));
            return new Response(200, [], '{"ok":true}');
        };

        $client = new OAuthTokenClient($http, self::TOKEN_URL, self::CREDENTIALS);
        $response = $client->sendRequest(new Request('GET', 'https://example.com/track'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testThrowsAuthExceptionWhenTokenEndpointFails(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(400, ['Content-Type' => 'application/json'],
            '{"error":"invalid_client"}');

        $client = new OAuthTokenClient($http, self::TOKEN_URL, self::CREDENTIALS);

        $this->expectException(AuthException::class);
        $client->sendRequest(new Request('GET', 'https://example.com/track'));
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Unit/OAuthTokenClientTest.php`
Expected: FAIL（OAuthTokenClient 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Http/OAuthTokenClient.php`：

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
        $authorized = $this->withAuthorization($request, $this->token());
        $response = $this->inner->sendRequest($authorized);

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

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($this->basicAuth) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                $this->credentials['client_id'] . ':' . $this->credentials['client_secret'],
            );
        }

        $request = new Request('POST', $this->tokenUrl, $headers, http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
        ]));

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

（若 `GlobalLogistics\Exceptions\NetworkException` 不存在或构造签名不同，按 `src/Exceptions/` 实际类调整——参照 `RetryingClient` 的用法。）

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
- Test: `tests/Carriers/DhlTest.php`
- Create: `tests/fixtures/dhl/track.json`、`tests/fixtures/dhl/empty.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/DhlTest.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DhlTest extends TestCase
{
    private const TOKEN_URL = 'https://api.dhl.com/mydhlapi/auth';

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"access_token":"tok-dhl","expires_in":3600}');
            }
            $this->assertSame('Bearer tok-dhl', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('trackingNumber=DHL1234567890', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dhl/track.json'));
        };

        $adapter = new Dhl(
            new Config(['dhl' => ['client_id' => 'cid', 'client_secret' => 'cs']]),
            $http,
        );

        $tracking = $adapter->queryTrack('DHL1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Delivered', $tracking->latestDescription);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoShipment(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/dhl/empty.json'));

        $adapter = new Dhl(new Config(['dhl' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('DHL1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}')
            : new Response(401, ['Content-Type' => 'application/json'], '{"title":"Invalid token","status":401}');

        $adapter = new Dhl(new Config(['dhl' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('DHL1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-dhl","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Dhl(new Config(['dhl' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('DHL1234567890');
    }
}
```

fixture `tests/fixtures/dhl/track.json`：

```json
{
    "shipments": [
        {
            "id": "DHL1234567890",
            "status": {"statusCode": "delivered", "status": "DELIVERED"},
            "events": [
                {"timestamp": "2026-08-14T10:00:00", "location": {"address": {"city": "Frankfurt", "countryCode": "DE"}}, "description": "Processed"},
                {"timestamp": "2026-08-15T18:30:00", "location": {"address": {"city": "Berlin", "countryCode": "DE"}}, "description": "Delivered", "statusCode": "delivered"}
            ]
        }
    ]
}
```

fixture `tests/fixtures/dhl/empty.json`：`{"shipments": []}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/DhlTest.php`
Expected: FAIL（Dhl 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Dhl.php`（OAuthTokenClient 包装，non-promoted `private readonly ClientInterface $http` 属性保持 CarrierFactory 两参构造兼容）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
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
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => (string) $this->config->get('dhl.client_id'), 'client_secret' => (string) $this->config->get('dhl.client_secret')],
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'trackingNumber' => $trackingNo,
        ]));

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[DHL] 响应解析失败');
        }

        $shipments = $result['shipments'] ?? [];
        if (!is_array($shipments) || $shipments === []) {
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
                    occurredAt: $this->mapEventTime($event['timestamp'] ?? null),
                    location: $this->mapLocation($event['location'] ?? null),
                    description: (string) ($event['description'] ?? ''),
                    status: $this->mapStatus((string) ($event['statusCode'] ?? $statusCode)),
                    raw: $event,
                );
            }
        }

        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // DHL events 按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'dhl',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->status->value,
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('DHL createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('DHL createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('DHL subscribe 待实现');
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

    private function mapLocation(mixed $raw): string
    {
        if (!is_array($raw)) {
            return '';
        }
        $parts = array_filter([
            $raw['address']['city'] ?? null,
            $raw['address']['countryCode'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
        return implode(', ', $parts);
    }

    /** DHL statusCode 官方枚举 */
    private function mapStatus(string $code): TrackStatus
    {
        return match ($code) {
            'delivered' => TrackStatus::DELIVERED,
            'pre-transit' => TrackStatus::PENDING,
            'transit' => TrackStatus::IN_TRANSIT,
            'failure', 'exception' => TrackStatus::EXCEPTION,
            'returned' => TrackStatus::RETURNED,
            default => TrackStatus::UNKNOWN,
        };
    }

    private function assertHttpOk(\Psr\Http\Message\ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[DHL %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[DHL %s] 接口错误', $status));
        }
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/DhlTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Dhl.php tests/Carriers/DhlTest.php tests/fixtures/dhl/
git commit -m "feat: add DHL international carrier adapter with OAuth"
```

## Task 7: FedEx 适配器（国际，OAuth）

**Files:**
- Create: `src/Carriers/International/FedEx.php`
- Test: `tests/Carriers/FedExTest.php`
- Create: `tests/fixtures/fedex/track.json`、`tests/fixtures/fedex/empty.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/FedExTest.php`（POST body `{trackingNumberInfo:{trackingNumber},includeDetailedScans:true}`，响应 `{output:{completeTrackResults:[{trackResults:[{statusByTrack,scanEvents}]}]}}`；**token URL 为 `https://apis.fedex.com/oauth/token`**）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class FedExTest extends TestCase
{
    private const TOKEN_URL = 'https://apis.fedex.com/oauth/token';

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if ((string) $request->getUri() === self::TOKEN_URL) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"access_token":"tok-fedex","expires_in":3600}');
            }
            $this->assertSame('Bearer tok-fedex', $request->getHeaderLine('Authorization'));
            $payload = json_decode((string) $request->getBody(), true);
            assert($payload['trackingNumberInfo']['trackingNumber'] === 'FEDEX1234567890');
            assert($payload['includeDetailedScans'] === true);

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/fedex/track.json'));
        };

        $adapter = new FedEx(
            new Config(['fedex' => ['client_id' => 'cid', 'client_secret' => 'cs']]),
            $http,
        );

        $tracking = $adapter->queryTrack('FEDEX1234567890');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
    }

    public function testQueryTrackThrowsWhenNoResult(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/fedex/empty.json'));

        $adapter = new FedEx(new Config(['fedex' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}')
            : new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_token"}');

        $adapter = new FedEx(new Config(['fedex' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('FEDEX1234567890');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => $request->getUri() === self::TOKEN_URL
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-fedex","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new FedEx(new Config(['fedex' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('FEDEX1234567890');
    }
}
```

fixture `tests/fixtures/fedex/track.json`：

```json
{
    "output": {
        "completeTrackResults": [
            {
                "trackResults": [
                    {
                        "statusByTrack": {"state": "DELIVERED", "description": "Delivered"},
                        "scanEvents": [
                            {"date": "2026-08-14", "time": "10:00:00", "scanLocation": {"city": "Memphis"}, "scanDescription": "Picked up"},
                            {"date": "2026-08-15", "time": "18:30:00", "scanLocation": {"city": "Berlin"}, "scanDescription": "Delivered"}
                        ]
                    }
                ]
            }
        ]
    }
}
```

fixture `tests/fixtures/fedex/empty.json`：`{"output": {"completeTrackResults": []}}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/FedExTest.php`
Expected: FAIL（FedEx 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/FedEx.php`（结构同 Dhl；差异：ENDPOINT `https://apis.fedex.com/track/v1/trackingnumbers`、TOKEN_URL `https://apis.fedex.com/oauth/token`、POST JSON body、config 键 `fedex.client_id/client_secret`、traces 取 `$result['output']['completeTrackResults'][0]['trackResults'][0]['scanEvents']`、状态优先 `statusByTrack.state`（`mapState`），事件用描述关键词 `mapStatus`、前缀 `[FEDEX]`）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
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

    /** FedEx 事件描述关键词 => 统一状态（以 scanDescription 内容匹配，大写比较） */
    private const STATUS_MAP = [
        'PICKUP' => TrackStatus::PENDING,
        'IN TRANSIT' => TrackStatus::IN_TRANSIT,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'DELIVERED' => TrackStatus::DELIVERED,
    ];

    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => (string) $this->config->get('fedex.client_id'), 'client_secret' => (string) $this->config->get('fedex.client_secret')],
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'trackingNumberInfo' => ['trackingNumber' => $trackingNo],
            'includeDetailedScans' => true,
        ]));

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[FEDEX] 响应解析失败');
        }

        $track = $result['output']['completeTrackResults'][0]['trackResults'][0] ?? null;
        if (!is_array($track)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        $status = $this->mapState((string) ($track['statusByTrack']['state'] ?? ''));
        foreach (($track['scanEvents'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $description = (string) ($event['scanDescription'] ?? $event['statusDescription'] ?? '');
            $events[] = new TrackingEvent(
                occurredAt: $this->mapEventTime($event['date'] ?? null, $event['time'] ?? null),
                location: (string) ($event['scanLocation']['city'] ?? ''),
                description: $description,
                status: $this->mapStatus($description),
                raw: $event,
            );
        }

        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        if ($status === TrackStatus::UNKNOWN) {
            $status = $events[count($events) - 1]->status;
        }

        // FedEx scanEvents 按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'fedex',
            trackingNo: $trackingNo,
            status: $status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($track['statusByTrack']['state'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('FEDEX createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('FEDEX createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('FEDEX subscribe 待实现');
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

    /** FedEx statusByTrack.state 官方枚举 */
    private function mapState(string $state): TrackStatus
    {
        return match (strtoupper($state)) {
            'DELIVERED' => TrackStatus::DELIVERED,
            'PICKUP' => TrackStatus::PENDING,
            'OUT_FOR_DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
            'IN_TRANSIT' => TrackStatus::IN_TRANSIT,
            'EXCEPTION', 'FAILURE' => TrackStatus::EXCEPTION,
            'RETURNED' => TrackStatus::RETURNED,
            default => TrackStatus::UNKNOWN,
        };
    }

    private function mapStatus(string $desc): TrackStatus
    {
        $upper = strtoupper($desc);
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($upper, $keyword)) {
                return $mapped;
            }
        }
        return TrackStatus::UNKNOWN;
    }

    private function assertHttpOk(\Psr\Http\Message\ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[FEDEX %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[FEDEX %s] 接口错误', $status));
        }
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/FedExTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/FedEx.php tests/Carriers/FedExTest.php tests/fixtures/fedex/
git commit -m "feat: add FedEx international carrier adapter with OAuth"
```

## Task 8: UPS 适配器（国际，OAuth + Basic Auth）

**Files:**
- Create: `src/Carriers/International/Ups.php`
- Test: `tests/Carriers/UpsTest.php`
- Create: `tests/fixtures/ups/track.json`、`tests/fixtures/ups/empty.json`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/UpsTest.php`（**OAuthTokenClient basicAuth: true**；GET `https://wwwcie.ups.com/api/track/v1/details/{no}`；header `transId`/`transactionSrc`；日期 `date`+`time` → `'YmdHis'`；状态 types：M/I/O/D/X/R；**token URL 为 `https://wwwcie.ups.com/security/v1/oauth/token`**）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UpsTest extends TestCase
{
    private const TOKEN_URL = 'https://wwwcie.ups.com/security/v1/oauth/token';

    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            if (str_contains((string) $request->getUri(), self::TOKEN_URL)) {
                return new Response(200, ['Content-Type' => 'application/json'],
                    '{"access_token":"tok-ups","expires_in":3600}');
            }
            $this->assertSame('Bearer tok-ups', $request->getHeaderLine('Authorization'));
            $this->assertNotSame('', $request->getHeaderLine('transId'));
            $this->assertSame('global-logistics', $request->getHeaderLine('transactionSrc'));
            $this->assertStringContainsString('/track/v1/details/1Z9999999999999999', (string) $request->getUri());

            return new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ups/track.json'));
        };

        $adapter = new Ups(
            new Config(['ups' => ['client_id' => 'cid', 'client_secret' => 'cs']]),
            $http,
        );

        $tracking = $adapter->queryTrack('1Z9999999999999999');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertNotNull($tracking->deliveredAt);
    }

    public function testQueryTrackThrowsWhenNoPackage(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => str_contains((string) $request->getUri(), self::TOKEN_URL)
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__ . '/../fixtures/ups/empty.json'));

        $adapter = new Ups(new Config(['ups' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackMapsHttpError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => str_contains((string) $request->getUri(), self::TOKEN_URL)
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}')
            : new Response(401, ['Content-Type' => 'application/json'], '{"response":{"errors":[]}}');

        $adapter = new Ups(new Config(['ups' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('1Z9999999999999999');
    }

    public function testQueryTrackThrowsOnNonArrayBody(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => str_contains((string) $request->getUri(), self::TOKEN_URL)
            ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok-ups","expires_in":3600}')
            : new Response(200, ['Content-Type' => 'application/json'], '"boom"');

        $adapter = new Ups(new Config(['ups' => ['client_id' => 'cid', 'client_secret' => 'cs']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('1Z9999999999999999');
    }
}
```

fixture `tests/fixtures/ups/track.json`：

```json
{
    "trackResponse": {
        "shipment": [
            {
                "package": [
                    {
                        "activity": [
                            {"date": "20260814", "time": "100000", "location": {"address": {"city": "Louisville", "countryCode": "US"}}, "status": {"type": "I", "description": "In Transit"}},
                            {"date": "20260815", "time": "183000", "location": {"address": {"city": "Berlin", "countryCode": "DE"}}, "status": {"type": "D", "description": "Delivered"}}
                        ]
                    }
                ]
            }
        ]
    }
}
```

fixture `tests/fixtures/ups/empty.json`：`{"trackResponse": {"shipment": []}}`

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/UpsTest.php`
Expected: FAIL（Ups 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Ups.php`（结构同 Dhl；差异：ENDPOINT `https://wwwcie.ups.com/api/track/v1/details`、TOKEN_URL `https://wwwcie.ups.com/security/v1/oauth/token`、**OAuthTokenClient basicAuth: true**、GET 路径拼接 `/{no}`、headers `transId`(bin2hex(random_bytes(8))) + `transactionSrc`('global-logistics')、config 键 `ups.client_id/client_secret`、activity 取 `$result['trackResponse']['shipment'][0]['package'][0]['activity']`、日期 `$activity['date'].$activity['time']` 用 `'YmdHis'` 解析、状态 `$activity['status']['type']` 走 `mapStatus`（M/I/O/D/X/R）、前缀 `[UPS]`）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

use GlobalLogistics\CarrierInterface;
use GlobalLogistics\Config;
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
        private readonly Config $config,
        ClientInterface $http,
    ) {
        $this->http = new OAuthTokenClient(
            $http,
            self::TOKEN_URL,
            ['client_id' => (string) $this->config->get('ups.client_id'), 'client_secret' => (string) $this->config->get('ups.client_secret')],
            basicAuth: true,
        );
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '/' . urlencode($trackingNo), [
            'transId' => bin2hex(random_bytes(8)),
            'transactionSrc' => 'global-logistics',
        ]);

        $response = $this->http->sendRequest($request);
        $this->assertHttpOk($response);

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new LogisticsException('[UPS] 响应解析失败');
        }

        $package = $result['trackResponse']['shipment'][0]['package'][0] ?? null;
        if (!is_array($package)) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach (($package['activity'] ?? []) as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $location = $activity['location'] ?? null;
            $events[] = new TrackingEvent(
                occurredAt: $this->mapEventTime($activity['date'] ?? null, $activity['time'] ?? null),
                location: is_array($location)
                    ? trim(($location['address']['city'] ?? '') . ' ' . ($location['address']['countryCode'] ?? ''))
                    : '',
                description: (string) ($activity['status']['description'] ?? ''),
                status: $this->mapStatus((string) ($activity['status']['type'] ?? '')),
                raw: $activity,
            );
        }

        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // UPS activity 按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'ups',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: (string) ($activity['status']['type'] ?? ''),
            raw: $result,
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('UPS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('UPS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('UPS subscribe 待实现');
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

    /** UPS status type 官方枚举：M/I/O/D/X/R */
    private function mapStatus(string $type): TrackStatus
    {
        return match (strtoupper($type)) {
            'D' => TrackStatus::DELIVERED,
            'M' => TrackStatus::PENDING,
            'I' => TrackStatus::IN_TRANSIT,
            'O' => TrackStatus::OUT_FOR_DELIVERY,
            'X' => TrackStatus::EXCEPTION,
            'R' => TrackStatus::RETURNED,
            default => TrackStatus::UNKNOWN,
        };
    }

    private function assertHttpOk(\Psr\Http\Message\ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new AuthException(sprintf('[UPS %s] 认证失败', $status));
        }
        if ($status >= 400) {
            throw new LogisticsException(sprintf('[UPS %s] 接口错误', $status));
        }
    }
}
```

注意：`rawStatus` 使用了循环后的 `$activity` 变量——若 `activity` 为空数组会在前面抛 TrackingNotFoundException，因此该变量一定存在；但更稳妥的做法是取 `$package['activity'][count(...)-1]`。实现时按此处理：`$rawActivities = $package['activity']; ... rawStatus: (string) ($rawActivities[count($rawActivities) - 1]['status']['type'] ?? '')`。

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/UpsTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Ups.php tests/Carriers/UpsTest.php tests/fixtures/ups/
git commit -m "feat: add UPS international carrier adapter with OAuth"
```

## Task 9: USPS 适配器（国际，XML API）

**Files:**
- Create: `src/Carriers/International/Usps.php`
- Test: `tests/Carriers/UspsTest.php`
- Create: `tests/fixtures/usps/track.xml`、`tests/fixtures/usps/empty.xml`、`tests/fixtures/usps/error.xml`

- [ ] **Step 1: 写失败测试**

`tests/Carriers/UspsTest.php`（GET `https://secure.shippingapis.com/ShippingAPI.dll?API=TrackV2&XML=...`；XML 属性断言 `USERID` 与 `TrackID ID`；`simplexml_load_string`；TrackDetail regex `'/^([A-Za-z]+ \d{1,2}, \d{4}, \d{1,2}:\d{2} [ap]m), (.*)$/'`、格式 `'F j, Y, g:i a'`；Error 元素 code 80040B1A/80040B1C/80040B20→AuthException）：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Carriers;

use GlobalLogistics\Carriers\International\Usps;
use GlobalLogistics\Config;
use GlobalLogistics\Exceptions\AuthException;
use GlobalLogistics\Exceptions\LogisticsException;
use GlobalLogistics\Exceptions\TrackingNotFoundException;
use GlobalLogistics\Support\TrackStatus;
use GlobalLogistics\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UspsTest extends TestCase
{
    public function testQueryTrackParses(): void
    {
        $http = new FakeHttpClient();
        $http->handler = function (Request $request) {
            $this->assertSame('GET', $request->getMethod());
            $query = \GuzzleHttp\Psr7\Query::parse($request->getUri()->getQuery());
            $this->assertSame('TrackV2', $query['API'] ?? null);
            $root = simplexml_load_string($query['XML']);
            $this->assertSame('test-user', (string) $root['USERID']);
            $this->assertSame('9400111899223197448523', (string) $root->TrackID['ID']);

            return new Response(200, ['Content-Type' => 'application/xml'],
                file_get_contents(__DIR__ . '/../fixtures/usps/track.xml'));
        };

        $adapter = new Usps(new Config(['usps' => ['user_id' => 'test-user']]), $http);

        $tracking = $adapter->queryTrack('9400111899223197448523');

        $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
        $this->assertCount(2, $tracking->events);
        $this->assertSame('Delivered', $tracking->latestDescription);
    }

    public function testQueryTrackThrowsWhenNoTrace(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/usps/empty.xml'));

        $adapter = new Usps(new Config(['usps' => ['user_id' => 'test-user']]), $http);

        $this->expectException(TrackingNotFoundException::class);
        $adapter->queryTrack('9400111899223197448523');
    }

    public function testQueryTrackMapsApiError(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'],
            file_get_contents(__DIR__ . '/../fixtures/usps/error.xml'));

        $adapter = new Usps(new Config(['usps' => ['user_id' => 'test-user']]), $http);

        $this->expectException(AuthException::class);
        $adapter->queryTrack('9400111899223197448523');
    }

    public function testQueryTrackThrowsOnInvalidXml(): void
    {
        $http = new FakeHttpClient();
        $http->handler = fn (Request $request) => new Response(200, ['Content-Type' => 'application/xml'], 'not xml at all');

        $adapter = new Usps(new Config(['usps' => ['user_id' => 'test-user']]), $http);

        $this->expectException(LogisticsException::class);
        $this->expectExceptionMessage('响应解析失败');
        $adapter->queryTrack('9400111899223197448523');
    }
}
```

fixture `tests/fixtures/usps/track.xml`：

```xml
<?xml version="1.0" encoding="UTF-8"?>
<TrackResponse>
    <TrackInfo ID="9400111899223197448523">
        <TrackSummary>Your item was delivered at 6:30 pm on August 15, 2026 in BERLIN.</TrackSummary>
        <TrackDetail>August 14, 2026, 10:00 am, Picked up, MEMPHIS</TrackDetail>
        <TrackDetail>August 15, 2026, 6:30 pm, Delivered, BERLIN</TrackDetail>
    </TrackInfo>
</TrackResponse>
```

fixture `tests/fixtures/usps/empty.xml`：

```xml
<?xml version="1.0" encoding="UTF-8"?>
<TrackResponse>
    <TrackInfo ID="9400111899223197448523">
        <TrackSummary>Label created, not yet in system</TrackSummary>
    </TrackInfo>
</TrackResponse>
```

fixture `tests/fixtures/usps/error.xml`：

```xml
<?xml version="1.0" encoding="UTF-8"?>
<TrackResponse>
    <Error>
        <Number>80040B1A</Number>
        <Description>Authorization failure.</Description>
    </Error>
</TrackResponse>
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/Carriers/UspsTest.php`
Expected: FAIL（Usps 类不存在）

- [ ] **Step 3: 写最小实现**

`src/Carriers/International/Usps.php`：

```php
<?php

declare(strict_types=1);

namespace GlobalLogistics\Carriers\International;

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

final class Usps implements CarrierInterface
{
    private const ENDPOINT = 'https://secure.shippingapis.com/ShippingAPI.dll';

    /** USPS 事件描述关键词 => 统一状态（以 TrackDetail 文本匹配，大写比较） */
    private const STATUS_MAP = [
        'ACCEPT' => TrackStatus::PENDING,
        'IN TRANSIT' => TrackStatus::IN_TRANSIT,
        'OUT FOR DELIVERY' => TrackStatus::OUT_FOR_DELIVERY,
        'EXCEPTION' => TrackStatus::EXCEPTION,
        'RETURN' => TrackStatus::RETURNED,
        'DELIVERED' => TrackStatus::DELIVERED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
    ) {
    }

    public function queryTrack(string $trackingNo, array $options = []): Tracking
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<TrackFieldRequest USERID="' . htmlspecialchars((string) $this->config->get('usps.user_id'), ENT_XML1) . '">'
            . '<TrackID ID="' . htmlspecialchars($trackingNo, ENT_XML1) . '"/>'
            . '</TrackFieldRequest>';

        $request = new \GuzzleHttp\Psr7\Request('GET', self::ENDPOINT . '?' . http_build_query([
            'API' => 'TrackV2',
            'XML' => $xml,
        ]));

        $response = $this->http->sendRequest($request);

        $parsed = @simplexml_load_string((string) $response->getBody());
        if ($parsed === false) {
            throw new LogisticsException('[USPS] 响应解析失败');
        }

        $this->throwForApiError($parsed);

        $trackInfo = $parsed->TrackInfo ?? null;
        if ($trackInfo === null || count($trackInfo->TrackDetail ?? []) === 0) {
            throw new TrackingNotFoundException($trackingNo);
        }

        $events = [];
        foreach ($trackInfo->TrackDetail as $detail) {
            $text = trim((string) $detail);
            if ($text === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z]+ \d{1,2}, \d{4}, \d{1,2}:\d{2} [ap]m), (.*)$/', $text, $m)) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('F j, Y, g:i a', $m[1]);
            $description = trim($m[2]);
            $events[] = new TrackingEvent(
                occurredAt: $dt === false ? null : $dt,
                location: '',
                description: $description,
                status: $this->mapStatus($description),
                raw: ['text' => $text],
            );
        }

        if ($events === []) {
            throw new TrackingNotFoundException($trackingNo);
        }

        // USPS TrackDetail 按时间升序返回，末条为最新（不同承运商顺序可能不同，复制模板时需核对）
        $latest = $events[count($events) - 1];

        return new Tracking(
            carrierCode: 'usps',
            trackingNo: $trackingNo,
            status: $latest->status,
            events: $events,
            deliveredAt: $latest->status === TrackStatus::DELIVERED ? $latest->occurredAt : null,
            latestDescription: $latest->description,
            rawStatus: $latest->description,
            raw: ['track_summary' => (string) ($trackInfo->TrackSummary ?? '')],
        );
    }

    public function createOrder(OrderRequest $request): Order
    {
        throw new LogisticsException('USPS createOrder 待实现');
    }

    public function createLabel(Order $order, array $options = []): Label
    {
        throw new LogisticsException('USPS createLabel 待实现');
    }

    public function subscribe(string $callbackUrl, array $options = []): void
    {
        throw new LogisticsException('USPS subscribe 待实现');
    }

    private function mapStatus(string $desc): TrackStatus
    {
        $upper = strtoupper($desc);
        foreach (self::STATUS_MAP as $keyword => $mapped) {
            if (str_contains($upper, $keyword)) {
                return $mapped;
            }
        }
        return TrackStatus::UNKNOWN;
    }

    private function throwForApiError(\SimpleXMLElement $parsed): void
    {
        $error = $parsed->Error ?? null;
        if ($error === null) {
            return;
        }
        $code = (string) $error->Number;
        $message = (string) $error->Description;
        if (in_array($code, ['80040B1A', '80040B1C', '80040B20'], true)) {
            throw new AuthException(sprintf('[USPS %s] %s', $code, $message));
        }

        throw new LogisticsException(sprintf('[USPS %s] %s', $code, $message));
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/Carriers/UspsTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 提交**

```bash
git add src/Carriers/International/Usps.php tests/Carriers/UspsTest.php tests/fixtures/usps/
git commit -m "feat: add USPS international carrier adapter with XML API"
```

## Task 10: 收口 — 检测规则合并、注册表、README、集成测试

**Files:**
- Modify: `src/Resources/detector-rules.php`（合并，不覆盖——**现有国际规则 DHL 前缀/GM/LH/1Z/通用 FedEx/RR 已存在，必须保留**）
- Modify: `src/Resources/carrier-registry.php`（追加 8 家）
- Modify: `README.md`
- Modify: `tests/Unit/DetectorTest.php`（追加 8 个用例 + EMS/FedEx 碰撞用例）
- Modify: `tests/Unit/LogisticsTest.php`（追加 DHL 国际通道集成用例）

- [ ] **Step 1: 合并检测规则（顺序敏感）**

`src/Resources/detector-rules.php` 当前内容（pattern => [channel, code]，按顺序首命中生效）：

```php
'domestic': /^SF\d{10,12}$/i, /^JT\d{10,15}$/i, /^\d{13}$/ (zto), /^YT\d{10,12}$/i
'international': /^DHL\d{10,15}$/i (dhl), /^1Z[0-9A-Z]{16}$/i (ups), /^[A-Z]{2}\d{9}[A-Z]{2}$/i (fedex), /^GM\d{9}$/i (dhl), /^LH\d{10,12}$/i (dhl), /^RR\d{12}$/i (royal-mail)
```

**追加规则（必须满足两条顺序约束）：**
1. `'/^77\d{11}$/'`（sto）**必须插入到 `'/^\d{13}$/'`（zto）之前**——77 开头 13 位数字否则被 zto 规则命中
2. `'/^E[A-Z]\d{9}CN$/i'`（ems）**必须位于任何 domestic 规则之前不冲突即可，但必须在 `'/^[A-Z]{2}\d{9}[A-Z]{2}$/i'`（fedex）之前**——`EA123456789CN` 同时匹配通用 FedEx 规则；EMS 规则放 domestic 区块（在 international 区块之前，天然满足）

最终文件（domestic 区块顺序）：SF、JT、**YD**、**77-sto**、13 位 zto、YT、**JD**、**EMS**；international 区块追加：**FEDEX 前缀**、**94-usps**、**纯 10 位 dhl**（带误命中警告注释）：

```php
<?php

declare(strict_types=1);

// pattern => [channel, carrierCode]；channel 取值 'domestic' | 'international'
return [
    '/^SF\d{10,12}$/i' => ['domestic', 'sf'],
    '/^JT\d{10,15}$/i' => ['domestic', 'jt'],
    '/^YD\d{8,16}$/i' => ['domestic', 'yd'],
    '/^77\d{11}$/' => ['domestic', 'sto'], // 必须在 /^\d{13}$/ 之前，否则 77 开头 13 位数字误判为 zto
    '/^\d{13}$/' => ['domestic', 'zto'],
    '/^YT\d{10,12}$/i' => ['domestic', 'yto'],
    '/^JD[A-Z0-9]{8,18}$/i' => ['domestic', 'jd'],
    '/^E[A-Z]\d{9}CN$/i' => ['domestic', 'ems'], // 必须在通用 FedEx 规则之前（EA...CN 同时匹配 /^[A-Z]{2}\d{9}[A-Z]{2}$/i）
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^[A-Z]{2}\d{9}[A-Z]{2}$/i' => ['international', 'fedex'],
    '/^FEDEX\d{10,15}$/i' => ['international', 'fedex'],
    '/^GM\d{9}$/i' => ['international', 'dhl'],
    '/^LH\d{10,12}$/i' => ['international', 'dhl'],
    '/^94\d{16,22}$/' => ['international', 'usps'],
    '/^\d{10}$/' => ['international', 'dhl'], // 纯 10 位数字误命中风险：DHL 纯数字单号，需用户确认
    '/^RR\d{12}$/i' => ['international', 'royal-mail'],
];
```

- [ ] **Step 2: 更新 carrier-registry.php**

`src/Resources/carrier-registry.php` 追加 `use` 导入与条目（保持现有风格）：

```php
use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Carriers\International\Usps;
```

domestic 数组追加：`'yd' => Yd::class, 'sto' => Sto::class, 'jd' => Jd::class, 'ems' => Ems::class,`
international 数组：`'dhl' => Dhl::class, 'fedex' => FedEx::class, 'ups' => Ups::class, 'usps' => Usps::class,`

- [ ] **Step 3: 新增 Detector 测试**

`tests/Unit/DetectorTest.php` 追加（沿用现有 `Detector::withDefaults()` 模式）：

```php
public function testDetectsYunda(): void
{
    $result = Detector::withDefaults()->detect('YD12345678');
    $this->assertSame(Channel::Domestic, $result->channel);
    $this->assertSame('yd', $result->carrierCode);
}

public function testDetectsShentongBeforeZtoRule(): void
{
    // 77 开头 13 位数字必须是 sto 而非 zto
    $result = Detector::withDefaults()->detect('773001234567890');
    $this->assertSame(Channel::Domestic, $result->channel);
    $this->assertSame('sto', $result->carrierCode);
}

public function testDetectsJd(): void
{
    $result = Detector::withDefaults()->detect('JD1234567890');
    $this->assertSame(Channel::Domestic, $result->channel);
    $this->assertSame('jd', $result->carrierCode);
}

public function testDetectsEmsBeforeGenericFedExRule(): void
{
    // EA...CN 同时匹配通用 FedEx 规则，EMS 规则必须先命中
    $result = Detector::withDefaults()->detect('EA123456789CN');
    $this->assertSame(Channel::Domestic, $result->channel);
    $this->assertSame('ems', $result->carrierCode);
}

public function testDetectsFedExPrefix(): void
{
    $result = Detector::withDefaults()->detect('FEDEX1234567890');
    $this->assertSame(Channel::International, $result->channel);
    $this->assertSame('fedex', $result->carrierCode);
}

public function testDetectsUps(): void
{
    $result = Detector::withDefaults()->detect('1Z9999999999999999');
    $this->assertSame(Channel::International, $result->channel);
    $this->assertSame('ups', $result->carrierCode);
}

public function testDetectsUsps(): void
{
    $result = Detector::withDefaults()->detect('9400111899223197448523');
    $this->assertSame(Channel::International, $result->channel);
    $this->assertSame('usps', $result->carrierCode);
}
```

（DHL 前缀已由现有 `testDetectsDhlInternational` 覆盖，无需重复。）

- [ ] **Step 4: 新增 Logistics 集成测试**

`tests/Unit/LogisticsTest.php` 追加（沿用 `setUp()` 中 `Logistics::reset()` 模式；registry 用默认 + 覆盖国际）：

```php
public function testTrackRoutesToInternationalChannel(): void
{
    $http = new FakeHttpClient();
    $http->handler = fn (Request $request) => (string) $request->getUri() === 'https://api.dhl.com/mydhlapi/auth'
        ? new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"tok","expires_in":3600}')
        : new Response(200, ['Content-Type' => 'application/json'],
            file_get_contents(__DIR__ . '/../fixtures/dhl/track.json'));

    Logistics::configure([
        'http_client' => $http,
        'dhl' => ['client_id' => 'cid', 'client_secret' => 'cs'],
        'registry' => [
            'domestic' => [],
            'international' => ['dhl' => Dhl::class],
        ],
    ]);

    $tracking = Logistics::track('DHL1234567890');

    $this->assertSame('dhl', $tracking->carrierCode);
    $this->assertSame(TrackStatus::DELIVERED, $tracking->status);
}
```

（需要追加 `use GlobalLogistics\Carriers\International\Dhl;`、`use GuzzleHttp\Psr7\Request;`、`use GuzzleHttp\Psr7\Response;` 导入。）

- [ ] **Step 5: 更新 README**

README 已接入承运商段落更新为：

```markdown
## 已接入承运商

- 国内：顺丰(sf)、中通(zto)、圆通(yto)、极兔(jt)、韵达(yd)、申通(sto)、京东(jd)、EMS(ems) —— 轨迹查询可用；下单/面单/订阅按承运商开放情况接入中
- 国际：DHL(dhl)、FedEx(fedex)、UPS(ups)、USPS(usps) —— 轨迹查询可用（OAuth2 认证，config 需 client_id/client_secret，USPS 需 user_id）；下单/面单/订阅按承运商开放情况接入中
```

快速开始 config 示例追加国际密钥并取消国际示例注释：

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

// 自动识别通道（国内/国际）与承运商
$tracking = Logistics::track('SF1234567890');

// 显式指定
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');
```

- [ ] **Step 6: 全量测试 + 提交**

Run: `vendor/bin/phpunit`
Expected: ALL PASS（现有 39 + 新增 ≈ 70+ tests）

```bash
git add src/Resources/detector-rules.php src/Resources/carrier-registry.php README.md tests/
git commit -m "feat: register domestic-b and international-a carriers, update detector rules"
```

---

## 执行纪律

- 每任务必须 TDD 顺序执行：先写失败测试 → 确认失败 → 最小实现 → 确认通过 → 提交
- **模型选择**：Task 1-4、9-10（国内复制模式/USPS XML/收口）用 haiku；Task 5（OAuthTokenClient 新组件）、6-8（国际 OAuth 模式）用 sonnet；所有 spec/quality 审查用 sonnet
- 保持既有模板加固：is_array 防护、'异常'/'退回' 在 '签收' 之前、升序注释、异常消息 `[XX code] message` 风格
- 每任务完成后两阶段审查（spec 合规 → 代码质量），审查发现问题由原 implementer 修复后复审
- 全部完成后：整体代码审查 → finishing-a-development-branch（本地合并回 main，用户既定偏好）
