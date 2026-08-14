# erikwang2013/global-logistics 设计文档

日期：2026-08-14
状态：已批准

## 1. 目标

构建 composer 包 `erikwang2013/global-logistics`（PHP 8.1+，PSR-4，通用包不绑定框架），接入国内所有主流快递物流官方 API 与国际物流/快递承运商，提供统一入口，自动区分国内/国际通道。

## 2. 需求澄清结论

| 问题 | 结论 |
|------|------|
| 团队形式 | AI 多 Agent 开发团队，波次并行推进 |
| 国内接入方式 | 官方 API 直连 |
| 功能范围 | 轨迹查询、在线下单、电子面单打印、轨迹订阅推送（全功能） |
| 国际覆盖 | 五大国际快递 + 专线小包 + 国际聚合平台 + 更多全球承运商 + 电商平台物流 + 架构预留 |
| 目标环境 | PHP 8.2+ 通用 composer 包（PSR-4，不依赖具体框架；2026-08-15 执行期由 8.1+ 上调，因 readonly class 语法为 8.2 特性） |
| 国内/国际入口 | 自动识别 + 可显式指定 |

## 3. 架构：统一门面 + 适配器模式（方案 A）

- 门面入口 `Logistics`，统一 `CarrierInterface`，每家承运商一个适配器类
- 新增承运商 = 新增一个适配器类 + 注册一条识别规则，调用方零改动
- 被否决方案：B（无统一抽象，调用方代码爆炸）、C（事件总线+队列，过度设计 YAGNI）

### 3.1 包结构

```
erikwang2013/global-logistics/
├── composer.json                    # PSR-4: GlobalLogistics\
├── src/
│   ├── Logistics.php                # 门面入口
│   ├── Channel.php                  # 枚举：Domestic / International
│   ├── CarrierInterface.php         # 统一接口：queryTrack/createOrder/createLabel/subscribe
│   ├── CarrierFactory.php           # 按 Channel + 承运商代码创建适配器
│   ├── Detector.php                 # 单号自动识别器（读规则表）
│   ├── Http/ClientFactory.php       # 统一 HTTP（PSR-18 兼容，可注入 Guzzle 等）
│   ├── Models/
│   │   ├── Tracking.php             # 轨迹结果（承运商、单号、状态、签收时间…）
│   │   ├── TrackingEvent.php        # 单条轨迹事件（时间、地点、描述、状态）
│   │   ├── Order.php                # 下单结果（单号、面单数据…）
│   │   └── Label.php                # 电子面单（PDF/图片内容）
│   ├── Exceptions/                  # LogisticsException 基类 + 5 个具体异常
│   ├── Support/TrackStatus.php      # 状态映射枚举
│   ├── Carriers/
│   │   ├── Domestic/                # 16 家
│   │   └── International/           # 16+ 通道
│   └── Resources/detector-rules.php # 单号规则表（前缀/长度/纯数字正则）
└── tests/                           # PHPUnit，fixtures/ 存各家 mock 响应
```

### 3.2 核心接口

```php
interface CarrierInterface
{
    public function queryTrack(string $trackingNo, array $options = []): Tracking;
    public function createOrder(OrderRequest $request): Order;
    public function createLabel(Order $order, array $options = []): Label;
    public function subscribe(string $callbackUrl, array $options = []): void;
}
```

### 3.3 门面入口

```php
Logistics::domestic('sf')->queryTrack('SF1234567890');        // 显式国内
Logistics::international('dhl')->queryTrack('DHL1234567890'); // 显式国际
Logistics::track('SF1234567890');                             // 自动识别通道+承运商
Logistics::detect('SF1234567890');                            // 返回识别结果（Channel + carrier code）
```

自动识别规则表示例：`SF` 开头 → 顺丰(国内)，`DHL` 开头 → DHL(国际)，10-13 位纯数字 → 按长度匹配国内小件等；无法识别抛 `CarrierNotFoundException` 并提示可手动指定。

## 4. 数据流

**查询**：调用方 → `Logistics::track($no)` → Detector 识别 (Channel, carrierCode) → CarrierFactory 创建适配器 → 适配器 queryTrack()（组装签名 → 统一 HTTP 客户端请求）→ 解析各家响应 → 归一化为统一 Tracking 模型 → 返回。

**订阅**：`subscribe($callbackUrl)` 注册到快递公司 → 快递公司轨迹变更推送 → 包内回调解析器把各家回调格式归一化为 TrackingEvent 列表（验证签名后交付）。

## 5. 错误处理

统一异常体系，适配器负责把各家错误码映射进来：

| 异常 | 触发场景 |
|------|----------|
| `LogisticsException` | 基类，兜底 |
| `CarrierNotFoundException` | 自动识别失败 / 未注册的承运商 |
| `TrackingNotFoundException` | 单号无轨迹（未揽收或查无此单） |
| `AuthException` | 密钥无效、签名校验失败、频率超限 |
| `NetworkException` | 超时、5xx、连接失败 |

网络层内置指数退避重试（默认 2 次，可配置）；密钥通过配置注入（`Config` 数组），不硬编码。

## 6. 状态映射

`TrackStatus` 枚举，所有承运商归一化：

```
PENDING(待揽收) → IN_TRANSIT(运输中) → OUT_FOR_DELIVERY(派送中)
→ DELIVERED(签收) | EXCEPTION(异常) | RETURNED(退回) | UNKNOWN(未知)
```

每家适配器写「原始状态词 → 统一枚举」映射表。

## 7. 能力分级（国际承运商）

- **Tier 1** 轨迹查询 + 订阅：所有承运商必做
- **Tier 2** 在线下单：仅开放下单 API 的承运商（DHL/FedEx/UPS 等）做
- **Tier 3** 电子面单：按承运商开放情况做
- 无官方 API 的承运商 → 自动走 17TRACK/AfterShip 聚合适配器兜底，调用入口统一

## 8. 承运商清单

### 国内（16 家，官方 API 直连，全功能）

电商快递 12 家：顺丰、中通、圆通、韵达、申通、京东、EMS、极兔、天天、苏宁、宅急送、跨越速运
快运/零担 4 家：德邦、安能、中通快运、百世快运

### 国际（16+ 通道，Tier 分级）

- 五大国际快递：DHL、FedEx、UPS、TNT、USPS
- 专线小包：燕文、云途、4PX
- 聚合平台：17TRACK、AfterShip（轨迹兜底，覆盖 1000+ 承运商）
- 更多全球承运商：DPD、Royal Mail、Canada Post、Australia Post、Japan Post、Aramex、Hermes/Evri、GLS
- 电商平台物流：菜鸟国际、Amazon Logistics
- 架构预留：后续按需扩展

## 9. 测试策略（无真实密钥也能全量跑测试）

| 测试层 | 内容 | 方式 |
|--------|------|------|
| 核心单测 | Detector 识别规则表、TrackStatus 映射、工厂创建 | 纯 PHPUnit |
| 适配器测试 | 每家承运商的请求签名、响应解析、错误码映射 | mock HTTP（PSR-18 注入 mock 客户端）+ `tests/fixtures/` 存各家真实响应样例 |
| 集成冒烟 | 配置真实密钥后手动跑，验证签名链路 | 单独 tag（`@live`），默认跳过 |

CI：GitHub Actions，PHP 8.2 / 8.3 双版本矩阵跑 PHPUnit（8.1 因 readonly class 语法退出支持）。

## 10. 团队执行计划（AI 多 Agent，每波 ≤5 并行）

```
Wave 0  架构师（主线程）：composer.json、目录骨架、CarrierInterface、
         Channel、异常体系、TrackStatus、Detector 规则表初版、配置装载、能力分级机制
Wave 1  4 个 Agent 并行（主战场，各 4 家）：
         国内 A：顺丰、中通、圆通、极兔
         国内 B：韵达、申通、京东、EMS
         国际 A：DHL、FedEx、UPS、USPS
         国际 B：TNT、专线小包(燕文/云途/4PX)、聚合平台(17TRACK/AfterShip)
Wave 2  5 个 Agent 并行（扩充战场）：
         国内 C：德邦、安能、中通快运、百世快运
         国内 D：天天、苏宁、宅急送、跨越速运
         国际 C：DPD、Royal Mail、Canada Post、Australia Post
         国际 D：Japan Post、Aramex、Hermes/Evri、GLS
         国际 E：菜鸟国际、Amazon Logistics
Wave 3  测试工程师补齐核心单测 + 适配器 mock 测试 + CI
         代码评审批量过审适配器
```

每位承运商开发 agent 交付物：适配器类 + 该家轨迹状态映射表 + mock 响应 fixture + 该家单测。

## 11. 交付物

- composer 包完整源码（composer.json + src + tests + README + LICENSE）
- `Logistics::track()` 开箱即用
- GitHub Actions CI
