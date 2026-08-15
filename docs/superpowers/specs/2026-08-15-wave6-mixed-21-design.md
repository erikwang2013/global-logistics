# Wave 6: 国内二线 6（天地华宇/佳吉/龙邦/速腾/中铁物流/全一）+ 国际 S10 邮政 15 Design

日期：2026-08-15
状态：已批准

## 目标

在 90 家基础上接入 21 家（国内 6 + 国际 15），共 111 家。

## 架构

完全沿用 Wave 5 模式（见 `docs/superpowers/specs/2026-08-15-wave5-mixed-21-design.md` 与 `docs/superpowers/plans/2026-08-15-wave5-mixed-21.md`）：

- 每适配器 = `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\{Domestic|International}`
- 构造器 `__construct(Config $config, ClientInterface $http)`（readonly 提升）；OAuth2 承运商用 `OAuthTokenClient` 包装（如需要）
- `ENDPOINT` const + `config->get('xx.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `config->get('xx.key')` 读取
- `STATUS_MAP` 关键词（小写，`|` 分隔同义）=> `TrackStatus`；`mapEvent`/`mapStatus`/`parseTime` 私有方法
- 错误契约：401/403 → `AuthException('[XX 401] 认证失败')`；>=400 → `LogisticsException('[XX 404] 接口错误')`；响应解析失败 → `LogisticsException('[XX] 响应解析失败')`；无事件 → `TrackingNotFoundException($trackingNo)`
- 事件升序排序；`deliveredAt` 仅当最新状态为 DELIVERED
- `createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('xx xxx 待实现')`（代码小写）
- docblock 标注 `VERIFIED-REQUIRED` + 公开文档来源链接
- 测试：每承运商 7 个用例（happy path 含 URL/方法/请求头断言、降序反转、无事件、业务错误、认证错误、非法 JSON/XML、未实现方法），fixtures 在 `tests/fixtures/{slug}/`

## 承运商清单（3 个并行代理，每代理 7 家，文件互不重叠）

| 代理 | 承运商 | 代码 | 类名 | 备注 |
|---|---|---|---|---|
| A | 天地华宇 | huayu | Huayu | 公路快运，KDN 协议 |
| A | 佳吉快运 | jiaji | Jiaji | KDN 协议 |
| A | 龙邦速递 | longbang | Longbang | KDN 协议 |
| A | 速腾物流 | suteng | Suteng | KDN 协议 |
| A | 中铁物流 | zhongtie | Zhongtie | 区别于中铁快运 cre |
| A | 全一快递 | qy | Qy | AAE，KDN 协议 |
| A | Ukrposhta（乌克兰） | ukrposhta | Ukrposhta | S10 UA |
| B | Turkey PTT（土耳其） | turkey-post | TurkeyPost | S10 TR |
| B | Israel Post（以色列） | israel-post | IsraelPost | S10 IL |
| B | Egypt Post（埃及） | egypt-post | EgyptPost | S10 EG |
| B | Saudi Post（沙特） | saudi-post | SaudiPost | S10 SA |
| B | South African Post（南非） | south-african-post | SouthAfricanPost | S10 ZA |
| B | Correos de México（墨西哥） | correos-mexico | CorreosMexico | S10 MX |
| C | Correo Argentino（阿根廷） | correo-argentino | CorreoArgentino | S10 AR |
| C | Correos de Chile（智利） | correos-chile | CorreosChile | S10 CL |
| C | Pos Indonesia（印尼） | pos-indonesia | PosIndonesia | S10 ID |
| C | PHLPost（菲律宾） | phl-post | PhlPost | S10 PH |
| C | Pakistan Post（巴基斯坦） | pakistan-post | PakistanPost | S10 PK |
| C | Kazpost（哈萨克斯坦） | kazpost | Kazpost | S10 KZ |
| C | Poșta Română（罗马尼亚） | romania-post | RomaniaPost | S10 RO |
| C | Hrvatska pošta（克罗地亚） | croatia-post | CroatiaPost | S10 HR |

## 共享文件（主会话统一注册，代理不得修改）

- `src/Resources/carrier-registry.php`
- `src/Resources/detector-rules.php`（代理只报告建议正则，含冲突分析）
- `config/logistics.php`
- `tests/Unit/DetectorTest.php`
- `tests/Unit/CarrierRegistrySmokeTest.php`（90 → 111 断言更新）
- `README.md`

## 检测规则决策（主会话注册时处理）

- **新增 15 个 S10 国家码规则**（UA/TR/IL/EG/SA/ZA/MX/AR/CL/ID/PH/PK/KZ/RO/HR），全部置于通用 FedEx 规则（`/^[A-Z]{2}\d{9}[A-Z]{2}$/i`）之前；15 个国家码与现有 36 个国家码规则均不重叠，无相互冲突
- **国内 6 家**：单号大概率与现有位数规则冲突（12 位 uc、13 位 zto、3 开头 13 位 zjs 等），按 Wave 4/5 决策**仅显式调用**；代理报告建议正则与冲突分析，主会话定夺
- 规则顺序：S10 新国家码插入现有 S10 区块内

## 验证

- 每代理对其承运商跑 `vendor/bin/phpunit tests/Carriers/XTest.php`
- 主会话注册后跑全量 `vendor/bin/phpunit`（预计 ~850 tests）
- 冒烟实例化测试断言更新为 111 家
- 提交信息沿用惯例：`feat: 接入 21 家新承运商（国内 6 + 国际 15），共 111 家`
