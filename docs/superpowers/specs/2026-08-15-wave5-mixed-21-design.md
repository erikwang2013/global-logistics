# Wave 5: 国内二线 6（丰网/百世快运/韵达快运/圆通快运/增益/民航）+ 国际 15（S10 邮政 11 + 中国国际 3 + 万邑通）Design

日期：2026-08-15
状态：已批准

## 目标

在 69 家基础上接入 21 家（国内 6 + 国际 15），共 90 家。

## 架构

完全沿用 Wave 4 模式（见 `docs/superpowers/plans/2026-08-15-wave4-mixed-12.md`）：

- 每适配器 = `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\{Domestic|International}`
- 构造器 `__construct(Config $config, ClientInterface $http)`（readonly 提升）；OAuth2 承运商用 `OAuthTokenClient` 包装（见 Yodel.php）
- `ENDPOINT` const + `config->get('xx.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `config->get('xx.key')` 读取
- `STATUS_MAP` 关键词（小写，`|` 分隔同义）=> `TrackStatus`；`mapEvent`/`mapStatus`/`parseTime` 私有方法
- 错误契约：401/403 → `AuthException('[XX 401] 认证失败')`；>=400 → `LogisticsException('[XX 404] 接口错误')`；响应解析失败 → `LogisticsException('[XX] 响应解析失败')`；无事件 → `TrackingNotFoundException($trackingNo)`
- 事件升序排序；`deliveredAt` 仅当最新状态为 DELIVERED
- `createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('xx xxx 待实现')`
- docblock 标注 `VERIFIED-REQUIRED` + 公开文档来源链接
- 测试：每承运商 7 个用例（happy path 含 URL/方法/请求头断言、降序反转、无事件、业务错误、认证错误、非法 JSON/XML、未实现方法），fixtures 在 `tests/fixtures/{slug}/`

## 承运商清单（3 个并行代理，每代理 7 家，文件互不重叠）

| 代理 | 承运商 | 代码 | 类名 | 备注 |
|---|---|---|---|---|
| A | 丰网速运 | fengwang | Fengwang | 顺丰加盟网，单号格式待代理核实 |
| A | 百世快运 | ht-freight | HtFreight | 区别于百世快递 ht |
| A | 韵达快运 | yd-freight | YdFreight | 区别于韵达 yd |
| A | 圆通快运 | yto-freight | YtoFreight | 区别于圆通 yto |
| A | 增益速递 | zy | Zy | |
| A | 民航快递 | cae | Cae | |
| A | 万邑通 | winit | Winit | 跨境仓配，若有明确前缀可注册检测规则 |
| B | PostNord（瑞典/丹麦） | postnord | PostNord | S10 SE/DK 两国家码 |
| B | CTT（葡萄牙） | ctt | Ctt | S10 PT |
| B | An Post（爱尔兰） | an-post | AnPost | S10 IE |
| B | Poczta Polska（波兰） | poczta-polska | PocztaPolska | S10 PL |
| B | India Post（印度） | india-post | IndiaPost | S10 IN |
| B | Pos Malaysia（马来西亚） | pos-malaysia | PosMalaysia | S10 MY |
| B | Emirates Post（阿联酋） | emirates-post | EmiratesPost | S10 AE |
| C | Magyar Posta（匈牙利） | magyar-posta | MagyarPosta | S10 HU |
| C | Česká pošta（捷克） | ceska-posta | CeskaPosta | S10 CZ |
| C | ELTA（希腊） | elta | Elta | S10 GR |
| C | Viettel Post（越南） | viettel-post | ViettelPost | S10 VN |
| C | 中通国际 | zto-intl | ZtoIntl | 单号可能同国内中通，倾向仅显式调用 |
| C | 圆通国际 | yto-intl | YtoIntl | 同上 |
| C | 极兔国际 | jt-intl | JtIntl | 同上 |

## 共享文件（主会话统一注册，代理不得修改）

- `src/Resources/carrier-registry.php`
- `src/Resources/detector-rules.php`（代理只报告建议正则，含冲突分析）
- `config/logistics.php`
- `tests/Unit/DetectorTest.php`
- `tests/Unit/CarrierRegistrySmokeTest.php`（69 → 90 断言更新）
- `README.md`

## 检测规则决策（主会话注册时处理）

- **新增 11 个 S10 国家码规则**（SE/DK/PT/IE/PL/IN/MY/AE/HU/CZ/GR/VN），全部置于通用 FedEx 规则（`/^[A-Z]{2}\d{9}[A-Z]{2}$/i`）之前
- **国内 6 家 + 中国国际 3 家 + 万邑通**：单号若与现有规则冲突（12 位 uc、13 位 zto、3 开头 13 位 zjs 等），按 Wave 4 决策**仅显式调用**；代理报告建议正则与冲突分析，主会话定夺
- 规则顺序：S10 新国家码插入现有 S10 区块内（国家码不重复，无相互冲突）

## 验证

- 每代理对其承运商跑 `vendor/bin/phpunit tests/Carriers/XTest.php`
- 主会话注册后跑全量 `vendor/bin/phpunit`（预计 ~700 tests）
- 冒烟实例化测试断言更新为 90 家
- 提交信息沿用惯例：`feat: 接入 21 家新承运商（国内 6 + 国际 15），共 90 家`
