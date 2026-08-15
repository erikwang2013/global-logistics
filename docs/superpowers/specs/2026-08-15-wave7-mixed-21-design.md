# Wave 7: 国内二线 6（中邮物流/汇通快运/全峰快递/国通快递/远成快运/新邦物流）+ 国际 S10 欧洲小国邮政 15 Design

日期：2026-08-15
状态：已批准

## 目标

在 111 家基础上接入 21 家（国内 6 + 国际 15），共 132 家。

## 架构

完全沿用 Wave 6 模式（见 `docs/superpowers/specs/2026-08-15-wave6-mixed-21-design.md` 与 `docs/superpowers/plans/2026-08-15-wave6-mixed-21.md`）：

- 每适配器 = `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\{Domestic|International}`
- 构造器 `__construct(Config $config, ClientInterface $http)`（readonly 提升）；OAuth2 承运商用 `OAuthTokenClient` 包装（如需要）
- `ENDPOINT` const + `config->get('xx.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `config->get('xx.key')` 读取
- `STATUS_MAP` 关键词（小写，`|` 分隔同义）=> `TrackStatus`；`mapEvent`/`mapStatus`/`parseTime` 私有方法
- 错误契约：401/403 → `AuthException('[XX 401] 认证失败')`；>=400 → `LogisticsException('[XX 404] 接口错误')`；响应解析失败 → `LogisticsException('[XX] 响应解析失败')`；无事件 → `TrackingNotFoundException($trackingNo)`
- 事件升序排序；`deliveredAt` 仅当最新状态为 DELIVERED
- `createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('xx xxx 待实现')`（代码小写）
- docblock 标注 `VERIFIED-REQUIRED` + 公开文档来源链接（禁止编造 URL）
- 测试：每承运商 7 个用例（happy path 含 URL/方法/请求头断言、降序反转、无事件、业务错误、认证错误、非法 JSON/XML、未实现方法），fixtures 在 `tests/fixtures/{slug}/`
- parseTime 纯日期格式一律 `!` 前缀（PHP 8.2+ 未指定时间部分会填当前时刻）

## 承运商清单（3 个并行代理，每代理 7 家，文件互不重叠）

| 代理 | 承运商 | 代码 | 类名 | 备注 |
|---|---|---|---|---|
| A | 中邮物流 | zhongyou | Zhongyou | 公路快运，KDN 协议 |
| A | 汇通快运 | huitong | Huitong | KDN 协议 |
| A | 全峰快递 | quanfeng | Quanfeng | KDN 协议 |
| A | 国通快递 | guotong | Guotong | KDN 协议 |
| A | 远成快运 | yuancheng | Yuancheng | KDN 协议 |
| A | 新邦物流 | xinbang | Xinbang | KDN 协议 |
| A | Slovak Post（斯洛伐克） | slovak-post | SlovakPost | S10 SK，官方 API |
| B | Pošta Slovenije（斯洛文尼亚） | slovenia-post | SloveniaPost | S10 SI，官方 API |
| B | Pošta Srbije（塞尔维亚） | serbia-post | SerbiaPost | S10 RS，官网 JSON |
| B | Bulgarian Posts（保加利亚） | bulgaria-post | BulgariaPost | S10 BG，官方 API |
| B | Lietuvos paštas（立陶宛） | lithuania-post | LithuaniaPost | S10 LT，官网 JSON |
| B | Latvijas Pasts（拉脱维亚） | latvia-post | LatviaPost | S10 LV，官方 API |
| B | Íslandspóstur（冰岛） | iceland-post | IcelandPost | S10 IS，官方 API |
| B | MaltaPost（马耳他） | malta-post | MaltaPost | S10 MT，官网 JSON |
| C | POST Luxembourg（卢森堡） | luxembourg-post | LuxembourgPost | S10 LU，官方 API |
| C | Cyprus Post（塞浦路斯） | cyprus-post | CyprusPost | S10 CY，官方 API |
| C | Poșta Moldovei（摩尔多瓦） | moldova-post | MoldovaPost | S10 MD，页面解析 |
| C | Posta Shqiptare（阿尔巴尼亚） | albania-post | AlbaniaPost | S10 AL，页面解析 |
| C | Belpochta（白俄罗斯） | belarus-post | BelarusPost | S10 BY，页面解析 |
| C | Makedonska Pošta（北马其顿） | macedonia-post | MacedoniaPost | S10 MK，页面解析 |
| C | BH Pošta（波黑） | bosnia-post | BosniaPost | S10 BA，页面解析 |

页面解析 5 家（MD/AL/BY/MK/BA）按 Wave 6 IsraelPost 先例（HTML 表格解析或页面内嵌 JSON）。

## 共享文件（主会话统一注册，代理不得修改）

- `src/Resources/carrier-registry.php`
- `src/Resources/detector-rules.php`（代理只报告建议正则，含冲突分析）
- `config/logistics.php`
- `tests/Unit/DetectorTest.php`
- `tests/Unit/CarrierRegistrySmokeTest.php`（111 → 132 断言更新）
- `README.md`

## 检测规则决策（主会话注册时处理）

- **新增 15 个 S10 国家码规则**（SK/SI/RS/BG/LT/LV/MD/AL/MT/LU/IS/CY/BY/MK/BA），全部置于通用 FedEx 规则（`/^[A-Z]{2}\d{9}[A-Z]{2}$/i`）之前；15 个国家码与现有 47 个国家码规则（CN GB JP HK BR KR FR NZ IT RU SG CH BE ES AT NO TH TW EE FI PT IE PL IN MY AE HU CZ GR VN UA TR IL EG SA ZA MX AR CL ID PH PK KZ RO HR）均不重叠，无相互冲突
- **国内 6 家**：单号大概率与现有位数规则冲突（12 位 uc、13 位 zto、3 开头 13 位 zjs 等），按 Wave 4/5/6 决策**仅显式调用**；代理报告建议正则与冲突分析，主会话定夺
- 规则顺序：S10 新国家码插入现有 S10 区块内

## 验证

- 每代理对其承运商跑 `vendor/bin/phpunit tests/Carriers/XTest.php`
- 主会话注册后跑全量 `vendor/bin/phpunit`（预计 ~1040 tests）
- 冒烟实例化测试断言更新为 132 家
- 提交信息沿用惯例：`feat: 接入 21 家新承运商（国内 6 + 国际 15），共 132 家`
