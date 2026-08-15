# Wave 2: 国内 C（天天/中通快运/丹鸟/中铁快运/顺心捷达）+ 国际 B（燕文/顺丰国际/TNT/ONTRAQ/Purolator/bpost/Correos）Implementation Plan

## 目标

在 45 家基础上再接入 12 家（国内 5 + 国际 7），共 57 家。

## 架构（沿用既有模式，见 Evri.php / Yodel.php / SwissPost.php 模板）

- 每适配器 = `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\{Domestic|International}`
- 构造器 `__construct(Config $config, ClientInterface $http)`（readonly 提升属性）；OAuth2 承运商在构造器内用 `OAuthTokenClient` 包装（见 Yodel.php）
- `ENDPOINT` const + `config->get('xx.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `config->get('xx.key')` 读取
- `STATUS_MAP` 关键词（小写，`|` 分隔同义）=> `TrackStatus`；`mapEvent`/`mapStatus`/`parseTime` 私有方法
- 错误契约：401/403 → `AuthException('[XX 401] 认证失败')`；>=400 → `LogisticsException('[XX 404] 接口错误')`；响应解析失败 → `LogisticsException('[XX] 响应解析失败')`；无事件 → `TrackingNotFoundException($trackingNo)`
- 事件升序排序（首尾比较降序则反转）；`deliveredAt` 仅当最新状态为 DELIVERED
- `createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('xx xxx 待实现')`
- docblock 标注 `VERIFIED-REQUIRED` + 公开文档来源链接
- 测试：每承运商 7 个用例（happy path 含 URL/方法/请求头断言、降序反转、无事件、业务错误、认证错误、非法 JSON、未实现方法），fixtures 在 `tests/fixtures/{slug}/`（track/empty/error，JSON 或 XML）

## 承运商清单（3 个并行代理，每代理 4 家，文件互不重叠）

| 代理 | 承运商 | 代码 | 类名 |
|---|---|---|---|
| A | 天天快递 | tiantian | Tiantian |
| A | 中通快运 | zto-freight | ZtoFreight |
| A | 丹鸟（菜鸟直送） | dainiao | Dainiao |
| A | 中铁快运 | cre | Cre |
| B | 顺心捷达 | sxjd | Sxjd |
| B | 燕文物流 | yanwen | Yanwen |
| B | 顺丰国际 | sf-international | SfInternational |
| B | TNT | tnt | Tnt |
| C | ONTRAQ | ontrac | Ontrac |
| C | Purolator | purolator | Purolator |
| C | bpost | bpost | Bpost |
| C | Correos | correos | Correos |

## 共享文件（由主会话统一注册，代理不得修改）

- `src/Resources/carrier-registry.php`
- `src/Resources/detector-rules.php`（代理只报告建议正则，含冲突分析）
- `config/logistics.php`
- `tests/Unit/DetectorTest.php`
- `README.md`

## 检测规则冲突预判（主会话注册时处理）

- 12 家邮政/国际 S10 国家码规则（BE/ES/NL 等）必须排在通用 FedEx 规则之前
- 纯数字格式（天天/中通快运/顺心捷达）需与 zto(13)/uc(12)/suning(20)/canada-post(16)/dhl(10) 位数规则错开或加前缀判别
- 顺丰国际 SF 前缀与国内顺丰规则冲突，需研究判别方式
- 燕文 CW 前缀为 S10 形态，需排在 FedEx 通用规则之前

## 验证

- 每代理对其承运商跑 `vendor/bin/phpunit tests/Carriers/XTest.php`
- 主会话注册后跑全量 `vendor/bin/phpunit`（预计 ~460 tests）
- 45 → 57 家冒烟实例化测试
