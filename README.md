# global-logistics

统一门面的国内快递 / 国际物流轨迹查询 composer 包（PHP 8.2+，PSR-4，不绑定框架）。

## 项目说明

面向电商、仓储、ERP 等业务系统，把「国内快递 + 国际物流」的官方 API 统一收敛为一个门面：

- **一条入口**：`Logistics::track($trackingNo)` 自动识别国内 / 国际通道与承运商，无需关心单号归属
- **一套数据模型**：所有承运商返回统一的 `Tracking` / `TrackingEvent` 结构，业务层只对接一种形状
- **一种状态语义**：承运商五花八门的原始状态映射为统一的 `TrackStatus` 枚举（7 种）
- **密钥零硬编码**：各家密钥全部经配置注入，代码与密钥完全分离

## 功能说明

### 已接入承运商（24 家）

| 通道 | 承运商 | 代码 | 轨迹查询 | 下单/面单/订阅 |
|---|---|---|---|---|
| 国内 | 顺丰 | `sf` | ✅ | 下单/面单/订阅按承运商开放情况接入中 |
| 国内 | 中通 | `zto` | ✅ | 同上 |
| 国内 | 圆通 | `yto` | ✅ | 同上 |
| 国内 | 极兔 | `jt` | ✅ | 同上 |
| 国内 | 韵达 | `yd` | ✅ | 同上 |
| 国内 | 申通 | `sto` | ✅ | 同上 |
| 国内 | 京东 | `jd` | ✅ | 同上 |
| 国内 | EMS | `ems` | ✅ | 同上 |
| 国内 | 百世 | `ht` | ✅ | 同上 |
| 国内 | 德邦 | `debon` | ✅ | 同上 |
| 国内 | 跨越 | `ky` | ✅ | 同上 |
| 国内 | 安能 | `ane` | ✅ | 同上 |
| 国际 | DHL | `dhl` | ✅（OAuth2） | 同上 |
| 国际 | FedEx | `fedex` | ✅（OAuth2） | 同上 |
| 国际 | UPS | `ups` | ✅（OAuth2） | 同上 |
| 国际 | USPS | `usps` | ✅ | 同上 |
| 国际 | 皇家邮政 | `royal-mail` | ✅（OAuth2） | 同上 |
| 国际 | 加拿大邮政 | `canada-post` | ✅ | 同上 |
| 国际 | 澳大利亚邮政 | `australia-post` | ✅ | 同上 |
| 国际 | 日本邮政 | `japan-post` | ✅（无认证） | 同上 |
| 国际 | Aramex | `aramex` | ✅ | 同上 |
| 国际 | GLS | `gls` | ✅ | 同上 |
| 国际 | DPD | `dpd` | ✅ | 同上 |
| 国际 | PostNL | `postnl` | ✅ | 同上 |

### 统一状态枚举（`GlobalLogistics\Support\TrackStatus`）

`PENDING`（待揽收）→ `IN_TRANSIT`（运输中）→ `OUT_FOR_DELIVERY`（派送中）→ `DELIVERED`（已签收）；异常归 `EXCEPTION`，退回归 `RETURNED`，无法识别归 `UNKNOWN`。

### 核心能力

- 单号自动检测（17 条正则规则，顺序敏感，优先命中国内规则）
- 统一轨迹查询（`Logistics::track()`）与显式通道调用（`domestic()` / `international()`）
- 统一异常体系（认证失败 / 单号不存在 / 网络错误 / 承运商未注册 / 接口错误）
- HTTP 基础设施：PSR-18 客户端、OAuth2 token 自动获取与缓存、失败自动重试
- 框架自动发现：Laravel / ThinkPHP 8 / Hyperf / Webman / Yii 2 即装即用
- 回调签名验证（顺丰示例：`verifyCallbackSignature()`）

## 使用说明

### 安装

```bash
composer require erikwang2013/global-logistics
```

### 配置

```php
<?php

use GlobalLogistics\Logistics;

Logistics::configure([
    // 国内
    'sf' => ['partner_id' => '...', 'checkword' => '...'],
    'zto' => ['company_id' => '...', 'secret' => '...'],
    'yto' => ['app_key' => '...', 'app_secret' => '...'],
    'jt' => ['api_key' => '...', 'secret' => '...'],
    'yd' => ['app_key' => '...', 'app_secret' => '...'],
    'sto' => [],
    'jd' => [],
    'ems' => ['app_id' => '...'],
    'ht' => ['partner_id' => '...', 'token' => '...'],
    'debon' => ['app_key' => '...', 'app_secret' => '...'],
    'ky' => ['app_key' => '...', 'app_secret' => '...'],
    'ane' => ['app_key' => '...'],
    // 国际
    'dhl' => ['client_id' => '...', 'client_secret' => '...'],
    'fedex' => ['client_id' => '...', 'client_secret' => '...'],
    'ups' => ['client_id' => '...', 'client_secret' => '...'],
    'usps' => ['user_id' => '...'],
    'royal-mail' => ['client_id' => '...', 'client_secret' => '...'],
    'canada-post' => ['customer_number' => '...', 'api_key' => '...'],
    'australia-post' => ['api_key' => '...'],
    'japan-post' => [],
    'aramex' => ['user_name' => '...', 'password' => '...', 'account_number' => '...'],
    'gls' => ['api_key' => '...'],
    'dpd' => ['user_name' => '...', 'password' => '...'],
    'postnl' => ['api_key' => '...'],

    // 可选：自定义 PSR-18 HTTP 客户端（默认自动构建 Guzzle）
    'http_client' => null,
    // 可选：失败重试次数（默认 2）
    'max_retries' => 2,
]);
```

> 框架项目（Laravel 等）可直接使用 `config/logistics.php` 模板，见「框架集成」。

### 查询轨迹

```php
// 自动识别通道（国内/国际）与承运商
$tracking = Logistics::track('SF1234567890');

// 显式指定（单号规则无法覆盖时）
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');

echo $tracking->status->name;       // DELIVERED
echo $tracking->latestDescription;  // 快件已签收
echo $tracking->carrierCode;        // sf
echo $tracking->deliveredAt?->format('Y-m-d H:i:s');  // 签收时间（已签收时）

foreach ($tracking->events as $event) {
    echo $event->occurredAt?->format('Y-m-d H:i:s'), ' ', $event->location, ' ', $event->description, PHP_EOL;
}
```

### 错误处理

所有异常继承 `GlobalLogistics\Exceptions\LogisticsException`，可统一捕获：

| 异常 | 场景 |
|---|---|
| `CarrierNotFoundException` | 单号无法识别承运商 |
| `TrackingNotFoundException` | 单号合法但承运商查无轨迹 |
| `AuthException` | 认证失败（密钥错误等） |
| `NetworkException` | HTTP 网络错误（实现 PSR-18 `NetworkExceptionInterface`） |
| `LogisticsException` | 其他接口/解析错误 |

```php
use GlobalLogistics\Exceptions\LogisticsException;

try {
    $tracking = Logistics::track('SF1234567890');
} catch (LogisticsException $e) {
    // 记录 $e->getMessage()，其中含承运商代码与原始错误码，如 "[SF A1001] 必传参数不可为空"
}
```

### 回调验签（订阅推送）

承运商开放订阅接口后，回调处理示例（以顺丰为例）：

```php
use GlobalLogistics\Logistics;

$carrier = Logistics::domestic('sf');
if (!$carrier->verifyCallbackSignature((string) file_get_contents('php://input'), (string) $_SERVER['HTTP_DIGEST'])) {
    http_response_code(401);
    exit('signature mismatch');
}
// 验签通过，处理轨迹推送……
```

## 架构设计

```
┌───────────────────────────── 业务层 ─────────────────────────────┐
│  Logistics 门面（静态）                                             │
│    track() / detect() / domestic() / international() / configure()│
└───────────────────────────────────────────────────────────────────┘
            │ detect 路由
┌───────────▼─────────────┐   ┌──────────────────────────────────────┐
│ Detector（单号规则）      │   │ CarrierFactory（注册表）              │
│ 国内/国际通道 + 承运商代码 │──▶│ 通道+代码 → 承运商适配器实例            │
└─────────────────────────┘   └────────────────┬─────────────────────┘
                                               │ 构造注入
                        ┌──────────────────────▼──────────────────────┐
                        │ 承运商适配器（CarrierInterface）              │
                        │  sf/zto/yto/.../dhl/fedex/ups/usps          │
                        │  queryTrack / createOrder / createLabel /   │
                        │  subscribe + 验签                            │
                        └──────────────────────┬──────────────────────┘
                                               │ PSR-18
                        ┌──────────────────────▼──────────────────────┐
                        │ HTTP 层                                      │
                        │  RetryingClient（失败重试）                    │
                        │  OAuthTokenClient（token 获取/缓存/401 刷新） │
                        │  HttpClientFactory（默认构建 Guzzle）          │
                        └──────────────────────┬──────────────────────┘
                                               │
                        ┌──────────────────────▼──────────────────────┐
                        │ 承运商官方 API（国内各家 / DHL、FedEx、UPS、USPS）│
                        └─────────────────────────────────────────────┘
```

### 各层职责

- **`Logistics`**（`src/Logistics.php`）：静态门面，持有全局配置、检测器与工厂；未配置时自动以空配置初始化
- **`Detector`**（`src/Detector.php` + `src/Resources/detector-rules.php`）：正则规则表按顺序首次命中，返回 `Detection`（通道 + 承运商代码）；规则顺序敏感（如 77 开头申通须先于纯 13 位数字规则）
- **`CarrierFactory`**（`src/CarrierFactory.php` + `src/Resources/carrier-registry.php`）：按「通道 → 代码 → 适配器类」注册表实例化适配器，统一注入 `Config` 与 HTTP 客户端
- **承运商适配器**（`src/Carriers/`）：实现 `CarrierInterface`，负责各家协议差异（签名、OAuth2、XML/JSON、状态映射）；同一模板结构（`ENDPOINT` 常量 + `STATUS_MAP` + `mapEvent()`），便于按模板新增承运商
- **HTTP 层**（`src/Http/`）：`OAuthTokenClient` 为 PSR-18 装饰器，懒获取 token、进程内缓存（提前 60s 过期）、401 时刷新重试一次；`RetryingClient` 按 `max_retries` 重试失败请求
- **模型层**（`src/Models/`）：`Tracking` / `TrackingEvent` 为不可变对象；`Order` / `OrderRequest` / `Label` 为下单、面单能力预留
- **`Config`**（`src/Config.php`）：点号键取值（`$config->get('dhl.client_id')`）
- **异常体系**（`src/Exceptions/`）：`LogisticsException` 为基类，细分 4 个场景异常

### 扩展新承运商

1. 新建适配器类（参照 `src/Carriers/Domestic/Yto.php` 模板）：实现 `CarrierInterface`，在 `mapEvent()` 中做状态映射
2. 注册表：`src/Resources/carrier-registry.php` 增加「通道 → 代码 → 类」
3. 单号规则：`src/Resources/detector-rules.php` 增加正则（注意顺序敏感，国内规则优先）
4. 补 fixture 与适配器测试（mock HTTP，无需真实密钥）

## 框架集成

`composer require erikwang2013/global-logistics` 后按框架自动发现，无需手工注册；配置模板统一为承运商代码为顶层键的数组（见 `config/logistics.php`，结构与 `Logistics::configure()` 入参一致）。

### Laravel

- 自动注册：composer `extra.laravel.providers` 包发现，无需配置
- 发布配置：`php artisan vendor:publish --tag=global-logistics`（生成 `config/logistics.php`）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### ThinkPHP 8

- 自动注册：composer `extra.think.services`（安装时生成 `vendor/services.php`，也可 `php think service:discover` 重新生成）
- 配置：应用 `config/logistics.php` 返回同结构数组（覆盖包内默认值）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Hyperf

- 自动发现：composer `extra.hyperf.config` 指向 ConfigProvider
- 发布配置：`php bin/hyperf.php vendor:publish`（发布到 `config/autoload/logistics.php`）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Webman

- 自动安装：webman 项目模板自带 `post-package-install` 等 composer 钩子，安装/更新时自动把配置拷贝到 `config/plugin/erikwang2013/global-logistics/`，卸载时自动删除
- 读取配置：`config('plugin.erikwang2013.global-logistics.app.sf.partner_id')`
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Yii 2

- 自动注册：包类型 `yii2-extension` + composer `extra.bootstrap`，应用每次引导时执行
- 配置：应用配置 `params` 中加 `'logistics' => [...同结构数组...]`
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`
- 若 `vendor/yiisoft/extensions.php` 未出现本包条目（罕见），运行 `composer dump-autoload` 重建

## 开发

```bash
composer install
composer test
```

无需真实密钥即可跑全量测试（适配器测试走 mock HTTP + fixture；框架集成测试使用真实框架类）。
