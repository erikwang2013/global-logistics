# Wave 8: 国际 S10 四区域 77 家 Design

日期：2026-08-15
状态：待审阅

## 目标

在 132 家基础上接入 77 家新承运商（国际 S10 邮政，四个区域并行），共 209 家；另复用 1 条检测规则（NL → 既有 postnl 适配器），新增 S10 规则合计 78 条。

四个方向全部实施（用户指令："1、2、3、4全都做了"）：

- A：欧洲剩余 + 西欧大邮政（14 家新适配器 + NL 复用）
- B：拉美 + 加勒比（21 家）
- C：非洲 + 中东（21 家）
- D：亚太剩余（21 家）

## 架构

完全沿用 Wave 7 模式（见 `docs/superpowers/specs/2026-08-15-wave7-mixed-21-design.md` 与 `docs/superpowers/plans/2026-08-15-wave7-mixed-21.md`）：

- 每适配器 = `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\International`
- 构造器 `__construct(Config $config, ClientInterface $http)`（readonly 提升）
- `ENDPOINT` const + `config->get('xx.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `config->get('xx.key')` 读取
- `STATUS_MAP` 关键词（小写，`|` 分隔同义）=> `TrackStatus`；`mapEvent`/`mapStatus`/`parseTime` 私有方法
- 错误契约：401/403 → `AuthException('[XX 401] 认证失败')`；>=400 → `LogisticsException('[XX 404] 接口错误')`；响应解析失败 → `LogisticsException('[XX] 响应解析失败')`；无事件 → `TrackingNotFoundException($trackingNo)`
- 事件升序排序；`deliveredAt` 仅当最新状态为 DELIVERED
- `createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('xx xxx 待实现')`（代码小写）
- docblock 标注 `VERIFIED-REQUIRED` + 公开文档来源链接（禁止编造 URL）；弱端点/依托母国邮政的承运商必须标注，端点经 `config` 可覆盖
- 测试：每承运商 7 个用例（happy path 含 URL/方法/请求头断言、降序反转、无事件、业务错误、认证错误、非法 JSON/XML、未实现方法），fixtures 在 `tests/fixtures/{slug}/`
- parseTime 纯日期格式一律 `!` 前缀（PHP 8.2+ 未指定时间部分会填当前时刻）；非 ASCII 文本一律 `mb_strtolower`（strtolower 不处理西里尔字母）
- 弱端点页面解析按 Wave 6 IsraelPost / Wave 7 BelarusPost 先例（HTML 表格或页面内嵌 JSON）

## 承运商清单（4 个并行代理，每代理 14-21 家，文件互不重叠）

| 代理 | 承运商 | 代码 | 类名 | 备注 |
|---|---|---|---|---|
| A | Deutsche Post（德国） | deutsche-post | DeutschePost | S10 DE |
| A | Montenegro Post（黑山） | montenegro-post | MontenegroPost | S10 ME |
| A | Andorra Post（安道尔） | andorra-post | AndorraPost | S10 AD，依托法/西邮政，VERIFIED-REQUIRED |
| A | La Poste Monaco（摩纳哥） | monaco-post | MonacoPost | S10 MC，依托法国邮政，VERIFIED-REQUIRED |
| A | Liechtenstein Post（列支敦士登） | liechtenstein-post | LiechtensteinPost | S10 LI，依托瑞士邮政，VERIFIED-REQUIRED |
| A | Poste San Marino（圣马力诺） | san-marino-post | SanMarinoPost | S10 SM，依托意大利邮政，VERIFIED-REQUIRED |
| A | Poste Vaticane（梵蒂冈） | vatican-post | VaticanPost | S10 VA，依托意大利邮政，VERIFIED-REQUIRED |
| A | Royal Gibraltar Post（直布罗陀） | gibraltar-post | GibraltarPost | S10 GI，VERIFIED-REQUIRED |
| A | Jersey Post（泽西） | jersey-post | JerseyPost | S10 JE |
| A | Guernsey Post（根西） | guernsey-post | GuernseyPost | S10 GG |
| A | Isle of Man Post（马恩岛） | isle-of-man-post | IsleOfManPost | S10 IM |
| A | Posta Faroe Islands（法罗群岛） | faroe-post | FaroePost | S10 FO，VERIFIED-REQUIRED |
| A | Post Greenland（格陵兰） | greenland-post | GreenlandPost | S10 GL，VERIFIED-REQUIRED |
| A | Post Åland（奥兰群岛） | aland-post | AlandPost | S10 AX，VERIFIED-REQUIRED |
| A（规则复用） | PostNL（荷兰） | postnl | （既有 Postnl 类） | 新增规则 NL → 既有 postnl，无新适配器 |
| B | 4-72（哥伦比亚） | colombia-post | ColombiaPost | S10 CO |
| B | Serpost（秘鲁） | peru-post | PeruPost | S10 PE |
| B | Correo Uruguayo（乌拉圭） | uruguay-post | UruguayPost | S10 UY |
| B | Correo Paraguayo（巴拉圭） | paraguay-post | ParaguayPost | S10 PY |
| B | Correos de Bolivia（玻利维亚） | bolivia-post | BoliviaPost | S10 BO |
| B | Correos del Ecuador（厄瓜多尔） | ecuador-post | EcuadorPost | S10 EC |
| B | Ipostel（委内瑞拉） | venezuela-post | VenezuelaPost | S10 VE，VERIFIED-REQUIRED |
| B | Correos de Costa Rica（哥斯达黎加） | costa-rica-post | CostaRicaPost | S10 CR |
| B | Correos de Panamá（巴拿马） | panama-post | PanamaPost | S10 PA |
| B | INPOSDOM（多米尼加） | dominican-post | DominicanPost | S10 DO |
| B | Correo de Guatemala（危地马拉） | guatemala-post | GuatemalaPost | S10 GT |
| B | HonduCorreo（洪都拉斯） | honduras-post | HondurasPost | S10 HN |
| B | Correos de El Salvador（萨尔瓦多） | el-salvador-post | ElSalvadorPost | S10 SV |
| B | Correos de Nicaragua（尼加拉瓜） | nicaragua-post | NicaraguaPost | S10 NI |
| B | Correos de Cuba（古巴） | cuba-post | CubaPost | S10 CU，VERIFIED-REQUIRED |
| B | Jamaica Post（牙买加） | jamaica-post | JamaicaPost | S10 JM |
| B | TTPOST（特立尼达和多巴哥） | trinidad-post | TrinidadPost | S10 TT，VERIFIED-REQUIRED |
| B | Barbados Post（巴巴多斯） | barbados-post | BarbadosPost | S10 BB，VERIFIED-REQUIRED |
| B | Bahamas Post（巴哈马） | bahamas-post | BahamasPost | S10 BS，VERIFIED-REQUIRED |
| B | Suriname Post（苏里南） | suriname-post | SurinamePost | S10 SR，VERIFIED-REQUIRED |
| B | Guyana Post（圭亚那） | guyana-post | GuyanaPost | S10 GY，VERIFIED-REQUIRED |
| C | Barid Al-Maghrib（摩洛哥） | morocco-post | MoroccoPost | S10 MA |
| C | Algérie Poste（阿尔及利亚） | algeria-post | AlgeriaPost | S10 DZ |
| C | La Poste Tunisienne（突尼斯） | tunisia-post | TunisiaPost | S10 TN |
| C | Posta Kenya（肯尼亚） | kenya-post | KenyaPost | S10 KE |
| C | NIPOST（尼日利亚） | nigeria-post | NigeriaPost | S10 NG，VERIFIED-REQUIRED |
| C | Ethiopia Post（埃塞俄比亚） | ethiopia-post | EthiopiaPost | S10 ET |
| C | Ghana Post（加纳） | ghana-post | GhanaPost | S10 GH |
| C | Tanzania Post（坦桑尼亚） | tanzania-post | TanzaniaPost | S10 TZ |
| C | Uganda Post（乌干达） | uganda-post | UgandaPost | S10 UG |
| C | Rwanda Post（卢旺达） | rwanda-post | RwandaPost | S10 RW |
| C | Zampost（赞比亚） | zambia-post | ZambiaPost | S10 ZM |
| C | Zimpost（津巴布韦） | zimbabwe-post | ZimbabwePost | S10 ZW |
| C | Mozambique Post（莫桑比克） | mozambique-post | MozambiquePost | S10 MZ |
| C | Correios de Angola（安哥拉） | angola-post | AngolaPost | S10 AO |
| C | La Poste Sénégalaise（塞内加尔） | senegal-post | SenegalPost | S10 SN |
| C | La Poste de Côte d'Ivoire（科特迪瓦） | ivory-coast-post | IvoryCoastPost | S10 CI |
| C | Cameroon Post（喀麦隆） | cameroon-post | CameroonPost | S10 CM |
| C | Mauritius Post（毛里求斯） | mauritius-post | MauritiusPost | S10 MU |
| C | Qatar Post（卡塔尔） | qatar-post | QatarPost | S10 QA |
| C | Kuwait Post（科威特） | kuwait-post | KuwaitPost | S10 KW |
| C | Bahrain Post（巴林） | bahrain-post | BahrainPost | S10 BH |
| D | Bangladesh Post（孟加拉） | bangladesh-post | BangladeshPost | S10 BD |
| D | Nepal Post（尼泊尔） | nepal-post | NepalPost | S10 NP |
| D | Sri Lanka Post（斯里兰卡） | sri-lanka-post | SriLankaPost | S10 LK |
| D | Myanmar Post（缅甸） | myanmar-post | MyanmarPost | S10 MM，VERIFIED-REQUIRED |
| D | Cambodia Post（柬埔寨） | cambodia-post | CambodiaPost | S10 KH |
| D | Laos Post（老挝） | laos-post | LaosPost | S10 LA |
| D | Mongolia Post（蒙古） | mongolia-post | MongoliaPost | S10 MN |
| D | Georgian Post（格鲁吉亚） | georgia-post | GeorgiaPost | S10 GE |
| D | Azərpoçt（阿塞拜疆） | azerbaijan-post | AzerbaijanPost | S10 AZ |
| D | HayPost（亚美尼亚） | armenia-post | ArmeniaPost | S10 AM |
| D | Uzbekistan Post（乌兹别克斯坦） | uzbekistan-post | UzbekistanPost | S10 UZ |
| D | Kyrgyz Post（吉尔吉斯斯坦） | kyrgyzstan-post | KyrgyzstanPost | S10 KG |
| D | Tajikistan Post（塔吉克斯坦） | tajikistan-post | TajikistanPost | S10 TJ |
| D | Turkmenistan Post（土库曼斯坦） | turkmenistan-post | TurkmenistanPost | S10 TM，VERIFIED-REQUIRED |
| D | Afghanistan Post（阿富汗） | afghanistan-post | AfghanistanPost | S10 AF，VERIFIED-REQUIRED |
| D | Bhutan Post（不丹） | bhutan-post | BhutanPost | S10 BT |
| D | Maldives Post（马尔代夫） | maldives-post | MaldivesPost | S10 MV |
| D | Brunei Post（文莱） | brunei-post | BruneiPost | S10 BN |
| D | Papua New Guinea Post（巴布亚新几内亚） | papua-post | PapuaPost | S10 PG |
| D | Fiji Post（斐济） | fiji-post | FijiPost | S10 FJ |
| D | Samoa Post（萨摩亚） | samoa-post | SamoaPost | S10 WS |

## 共享文件（主会话统一注册，代理不得修改）

- `src/Resources/carrier-registry.php`
- `src/Resources/detector-rules.php`（代理只报告建议正则，含冲突分析）
- `config/logistics.php`
- `tests/Unit/DetectorTest.php`
- `tests/Unit/CarrierRegistrySmokeTest.php`（132 → 209 断言更新）
- `README.md`

## 检测规则决策（主会话注册时处理）

- **新增 78 个 S10 国家码规则**（A 15：DE ME AD MC LI SM VA GI JE GG IM FO GL AX NL；B 21：CO PE UY PY BO EC VE CR PA DO GT HN SV NI CU JM TT BB BS SR GY；C 21：MA DZ TN KE NG ET GH TZ UG RW ZM ZW MZ AO SN CI CM MU QA KW BH；D 21：BD NP LK MM KH LA MN GE AZ AM UZ KG TJ TM AF BT MV BN PG FJ WS）
- 与现有 62 个国家码规则（GB JP BR HK KR FR NZ IT RU SG CH BE ES AT NO TH TW EE FI SE DK PT IE PL IN MY AE HU CZ GR VN UA TR IL EG SA ZA MX AR CL ID PH PK KZ RO HR SK SI RS BG LT LV MD AL MT LU IS CY BY MK BA）及燕文（YP/YW/YE/YL）全部不重叠，已逐一核对，无相互冲突
- 全部置于通用 FedEx 规则（`/^[A-Z]{2}\d{9}[A-Z]{2}$/i`）之前；NL 规则映射到既有 `postnl` 适配器（同公司，复用）
- 注意：既有规则 `/^CA\d{9,12}$/i`（国内菜鸟）与加拿大无关，本批无 CA；本批全部为国家码后缀，与既有字母前缀规则（SF/JT/YD/YT/JD/DPK 等）无重叠

## 备用替换条款

若某承运商在实现期被证实无可验证端点（尤其 VERIFIED-REQUIRED 项），代理从本区域备用清单替换并在报告中说明：

- A：无（欧洲已近饱和）；可改用同队内调整
- B：BZ（伯利兹）、GD（格林纳达）、LC（圣卢西亚）、VC（圣文森特）、AG（安提瓜）、DM（多米尼克）、KN（圣基茨）、AW（阿鲁巴）、CW（库拉索）
- C：JO（约旦）、LB（黎巴嫩）、OM（阿曼）、IQ（伊拉克）、NA（纳米比亚）、BW（博茨瓦纳）、MG（马达加斯加）、ML（马里）、BF（布基纳法索）、TG（多哥）
- D：MO（中国澳门）、TO（汤加）、VU（瓦努阿图）、SB（所罗门群岛）、TL（东帝汶）、KI（基里巴斯）

替换后主会话在注册阶段核对新代码与现有规则无冲突。

## 验证

- 每代理对其承运商跑 `vendor/bin/phpunit tests/Carriers/XTest.php`
- 主会话注册后跑全量 `vendor/bin/phpunit`（预计 ~1660 tests：现有 1046 + 77×7 承运商测试 + 78 检测测试）
- 冒烟实例化测试断言更新为 209 家
- 提交信息沿用惯例：`feat: 接入 77 家新承运商（国际 S10 四区域），共 209 家`
