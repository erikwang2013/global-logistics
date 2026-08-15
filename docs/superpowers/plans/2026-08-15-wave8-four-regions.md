# Wave 8: 国际 S10 四区域 77 家 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 接入 77 家新承运商（国际 S10 邮政，欧洲剩余/拉美加勒比/非洲中东/亚太剩余四区域并行），注册表 132 → 209 家，新增 78 条 S10 检测规则（含 NL → 既有 postnl 复用），全量测试通过并提交。

**Architecture:** 完全沿用 Wave 7 模式：每承运商一个 `CarrierInterface` 适配器（命名空间 `GlobalLogistics\Carriers\International`），4 个并行代理各 14-21 家（文件互不重叠），主会话统一注册共享文件（registry / detector-rules / config / DetectorTest / 冒烟断言 / README）后跑全量测试并提交。

**Tech Stack:** PHP 8.2+、PSR-4、PHPUnit 10.5（当前 1046 tests）、Guzzle PSR-18、无框架绑定。

---

## 代理工作说明书（4 个实施代理必须全部遵守）

### 适配器模板

- `final class X implements CarrierInterface`，命名空间 `GlobalLogistics\Carriers\International`
- 构造器 `__construct(private readonly Config $config, private readonly ClientInterface $http)`（readonly 提升）
- `private const ENDPOINT` + `$this->config->get('{slug}.endpoint', self::ENDPOINT)` 覆盖；认证密钥经 `$this->config->get('{slug}.key')` 读取
- `queryTrack(string $trackingNo, array $options = []): Tracking` 实现轨迹查询；`createOrder`/`createLabel`/`subscribe` 抛 `LogisticsException('{code} createOrder 待实现')`（**代码小写**，如 `'morocco-post createOrder 待实现'`）
- **参照 `src/Carriers/International/PostNord.php`、`src/Carriers/International/Ctt.php`、`src/Carriers/International/EmiratesPost.php`**（JSON API 差异自行核实）；无公开 JSON API 时按 Wave 6 IsraelPost / Wave 7 BelarusPost 页面解析先例（HTML 表格或页面内嵌 JSON，**有 JSON 接口优先 JSON**）
- `STATUS_MAP`：小写关键词（`|` 分隔同义）=> `TrackStatus`；`mapStatus(string): TrackStatus` 未命中兜底 `UNKNOWN`；`mapEvent` 解析单条事件（occurredAt/location/description/status）；`parseTime` 处理多格式时间戳
- **parseTime 纯日期格式一律 `!` 前缀**（如 `'!Y-m-d'`/`'!d/m/Y'`/`'!d-m-Y'`），否则 PHP 8.2+ 会把未指定时间部分填成当前时刻（wave6 踩坑修复）
- **非 ASCII 文本（西里尔字母等）一律 `mb_strtolower`**（`function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text)`；`strtolower` 不处理西里尔字母，wave7 踩坑修复）
- 事件按 occurredAt 升序排序（若首事件 > 末事件则 `array_reverse`，即 `ensureAscending` 模式）；`deliveredAt` 仅当最新状态为 DELIVERED
- docblock 标注 `VERIFIED-REQUIRED:` + 公开官方文档 URL（状态映射依据，**禁止编造**；查不到的字段用默认 `IN_TRANSIT` 兜底并在汇报中说明）

### 错误契约（逐字，XX = 大写承运商代码）

- HTTP 401/403 → `AuthException('[XX 401] 认证失败')`
- HTTP >=400 → `LogisticsException('[XX 404] 接口错误')`（code 用实际状态码）
- 响应解析失败（非数组/非法 JSON/XML）→ `LogisticsException('[XX] 响应解析失败')`
- 无事件 → `TrackingNotFoundException($trackingNo)`

### 测试模板（每承运商 7 个用例，参照 `tests/Carriers/PostNordTest.php`（JSON API）或 `tests/Carriers/IsraelPostTest.php`（页面解析））

1. `testQueryTrackParses`：happy path，断言请求 URL、方法、请求头（认证）、状态映射、事件升序、deliveredAt
2. `testDescendingTracesAreReversed`：内联 mock 返回降序 fixture，断言升序
3. `testEmptyTracesThrowsTrackingNotFoundException`：`tests/fixtures/{slug}/empty.json`
4. `testBusinessErrorThrowsLogisticsException`：`tests/fixtures/{slug}/error.json` + 400 响应
5. `testAuthFailuresThrowAuthException`：401（无认证概念的承运商用 403 或响应体含错误码）
6. `testNonArrayBodyThrowsLogisticsException`：`'"boom"'` 响应
7. `testUnimplementedMethodsThrow`：createOrder/createLabel/subscribe 三个断言

fixtures：`tests/fixtures/{slug}/{track,empty,error}.json`（`FakeHttpClient` 要求响应带 Content-Type 头，fixture 内容为合成数据）。

### 交付要求

- **不修改共享文件**：`src/Resources/carrier-registry.php`、`src/Resources/detector-rules.php`、`config/logistics.php`、`tests/Unit/DetectorTest.php`、`tests/Unit/CarrierRegistrySmokeTest.php`、`README.md`
- 不 commit、不 push
- 代理各自报告：每家 `config->get` 点号键清单 + 建议检测正则（含冲突分析，若有）+ 官方文档 URL + 是否替换为备选
- 自检：`vendor/bin/phpunit tests/Carriers/{X}Test.php` 全绿（每家 7 用例）
- 汇报格式：状态（DONE / BLOCKED / NEEDS_CONTEXT / DONE_WITH_CONCERNS）+ 上述报告项

---

### Task 1: 基线验证

- [ ] **Step 1: 跑全量测试确认基线**

Run: `vendor/bin/phpunit`
Expected: `OK (1046 tests, ...)`（绿色）

- [ ] **Step 2: 确认冒烟断言为 132**

Run: `grep -n "assertSame(132" tests/Unit/CarrierRegistrySmokeTest.php`
Expected: `$this->assertSame(132, $count);`

### Task 2: 代理 A — 欧洲剩余 14 + NL 规则复用

**Files（全部新建）：**
- Create: `src/Carriers/International/{DeutschePost,MontenegroPost,AndorraPost,MonacoPost,LiechtensteinPost,SanMarinoPost,VaticanPost,GibraltarPost,JerseyPost,GuernseyPost,IsleOfManPost,FaroePost,GreenlandPost,AlandPost}.php`
- Create: `tests/Carriers/{DeutschePost,MontenegroPost,AndorraPost,MonacoPost,LiechtensteinPost,SanMarinoPost,VaticanPost,GibraltarPost,JerseyPost,GuernseyPost,IsleOfManPost,FaroePost,GreenlandPost,AlandPost}Test.php`
- Create: `tests/fixtures/{deutsche-post,montenegro-post,andorra-post,monaco-post,liechtenstein-post,san-marino-post,vatican-post,gibraltar-post,jersey-post,guernsey-post,isle-of-man-post,faroe-post,greenland-post,aland-post}/`

- [ ] **Step 1: 派发实施代理 A**（背景运行，14 家，文件集合与代理 B/C/D 不重叠）

代理 A 任务书：
1. 按「代理工作说明书」实现 14 个适配器：Deutsche Post 德国 `deutsche-post`/DeutschePost（S10 DE）、Montenegro Post 黑山 `montenegro-post`/MontenegroPost（ME）、Andorra Post 安道尔 `andorra-post`/AndorraPost（AD）、La Poste Monaco 摩纳哥 `monaco-post`/MonacoPost（MC）、Liechtenstein Post 列支敦士登 `liechtenstein-post`/LiechtensteinPost（LI）、Poste San Marino 圣马力诺 `san-marino-post`/SanMarinoPost（SM）、Poste Vaticane 梵蒂冈 `vatican-post`/VaticanPost（VA）、Royal Gibraltar Post 直布罗陀 `gibraltar-post`/GibraltarPost（GI）、Jersey Post 泽西 `jersey-post`/JerseyPost（JE）、Guernsey Post 根西 `guernsey-post`/GuernseyPost（GG）、Isle of Man Post 马恩岛 `isle-of-man-post`/IsleOfManPost（IM）、Posta Faroe Islands 法罗群岛 `faroe-post`/FaroePost（FO）、Post Greenland 格陵兰 `greenland-post`/GreenlandPost（GL）、Post Åland 奥兰群岛 `aland-post`/AlandPost（AX）
2. 依托母国邮政的小国（AD/MC/LI/SM/VA/FO/GL/AX）无独立官方 API 时按页面解析先例实现，端点标注 VERIFIED-REQUIRED 且可经 config 覆盖；官方文档核实，**禁止编造 URL**
3. 若某家被证实无可验证端点，按规格备用条款说明（A 区无备选 → 同队内调整）
4. 自检 14 个测试文件全绿后返回 DONE

- [ ] **Step 2: 验证代理 A 输出**

Run: `vendor/bin/phpunit tests/Carriers/DeutschePostTest.php tests/Carriers/MontenegroPostTest.php tests/Carriers/AndorraPostTest.php tests/Carriers/MonacoPostTest.php tests/Carriers/LiechtensteinPostTest.php tests/Carriers/SanMarinoPostTest.php tests/Carriers/VaticanPostTest.php tests/Carriers/GibraltarPostTest.php tests/Carriers/JerseyPostTest.php tests/Carriers/GuernseyPostTest.php tests/Carriers/IsleOfManPostTest.php tests/Carriers/FaroePostTest.php tests/Carriers/GreenlandPostTest.php tests/Carriers/AlandPostTest.php`
Expected: `OK (98 tests, ...)` 全绿；抽查 1-2 家适配器核对错误契约、`ensureAscending`、parseTime `!` 前缀与 mb_strtolower

### Task 3: 代理 B — 拉美 + 加勒比 21

**Files（全部新建）：**
- Create: `src/Carriers/International/{ColombiaPost,PeruPost,UruguayPost,ParaguayPost,BoliviaPost,EcuadorPost,VenezuelaPost,CostaRicaPost,PanamaPost,DominicanPost,GuatemalaPost,HondurasPost,ElSalvadorPost,NicaraguaPost,CubaPost,JamaicaPost,TrinidadPost,BarbadosPost,BahamasPost,SurinamePost,GuyanaPost}.php`
- Create: `tests/Carriers/{ColombiaPost,PeruPost,UruguayPost,ParaguayPost,BoliviaPost,EcuadorPost,VenezuelaPost,CostaRicaPost,PanamaPost,DominicanPost,GuatemalaPost,HondurasPost,ElSalvadorPost,NicaraguaPost,CubaPost,JamaicaPost,TrinidadPost,BarbadosPost,BahamasPost,SurinamePost,GuyanaPost}Test.php`
- Create: `tests/fixtures/{colombia-post,peru-post,uruguay-post,paraguay-post,bolivia-post,ecuador-post,venezuela-post,costa-rica-post,panama-post,dominican-post,guatemala-post,honduras-post,el-salvador-post,nicaragua-post,cuba-post,jamaica-post,trinidad-post,barbados-post,bahamas-post,suriname-post,guyana-post}/`

- [ ] **Step 1: 派发实施代理 B**（背景运行，21 家）

代理 B 任务书：
1. 按「代理工作说明书」实现 21 个适配器：4-72 哥伦比亚 `colombia-post`/ColombiaPost（S10 CO）、Serpost 秘鲁 `peru-post`/PeruPost（PE）、Correo Uruguayo 乌拉圭 `uruguay-post`/UruguayPost（UY）、Correo Paraguayo 巴拉圭 `paraguay-post`/ParaguayPost（PY）、Correos de Bolivia 玻利维亚 `bolivia-post`/BoliviaPost（BO）、Correos del Ecuador 厄瓜多尔 `ecuador-post`/EcuadorPost（EC）、Ipostel 委内瑞拉 `venezuela-post`/VenezuelaPost（VE）、Correos de Costa Rica 哥斯达黎加 `costa-rica-post`/CostaRicaPost（CR）、Correos de Panamá 巴拿马 `panama-post`/PanamaPost（PA）、INPOSDOM 多米尼加 `dominican-post`/DominicanPost（DO）、Correo de Guatemala 危地马拉 `guatemala-post`/GuatemalaPost（GT）、HonduCorreo 洪都拉斯 `honduras-post`/HondurasPost（HN）、Correos de El Salvador 萨尔瓦多 `el-salvador-post`/ElSalvadorPost（SV）、Correos de Nicaragua 尼加拉瓜 `nicaragua-post`/NicaraguaPost（NI）、Correos de Cuba 古巴 `cuba-post`/CubaPost（CU）、Jamaica Post 牙买加 `jamaica-post`/JamaicaPost（JM）、TTPOST 特立尼达和多巴哥 `trinidad-post`/TrinidadPost（TT）、Barbados Post 巴巴多斯 `barbados-post`/BarbadosPost（BB）、Bahamas Post 巴哈马 `bahamas-post`/BahamasPost（BS）、Suriname Post 苏里南 `suriname-post`/SurinamePost（SR）、Guyana Post 圭亚那 `guyana-post`/GuyanaPost（GY）
2. VERIFIED-REQUIRED 项（VE/CU/TT/BB/BS/SR/GY）端点可经 config 覆盖；官方文档核实，**禁止编造 URL**
3. 若某家被证实无可验证端点，按规格备用条款替换（BZ/GD/LC/VC/AG/DM/KN/AW/CW）并在汇报中说明
4. 自检 21 个测试文件全绿后返回 DONE

- [ ] **Step 2: 验证代理 B 输出**

Run: `vendor/bin/phpunit tests/Carriers/ColombiaPostTest.php tests/Carriers/PeruPostTest.php tests/Carriers/UruguayPostTest.php tests/Carriers/ParaguayPostTest.php tests/Carriers/BoliviaPostTest.php tests/Carriers/EcuadorPostTest.php tests/Carriers/VenezuelaPostTest.php tests/Carriers/CostaRicaPostTest.php tests/Carriers/PanamaPostTest.php tests/Carriers/DominicanPostTest.php tests/Carriers/GuatemalaPostTest.php tests/Carriers/HondurasPostTest.php tests/Carriers/ElSalvadorPostTest.php tests/Carriers/NicaraguaPostTest.php tests/Carriers/CubaPostTest.php tests/Carriers/JamaicaPostTest.php tests/Carriers/TrinidadPostTest.php tests/Carriers/BarbadosPostTest.php tests/Carriers/BahamasPostTest.php tests/Carriers/SurinamePostTest.php tests/Carriers/GuyanaPostTest.php`
Expected: `OK (147 tests, ...)` 全绿

### Task 4: 代理 C — 非洲 + 中东 21

**Files（全部新建）：**
- Create: `src/Carriers/International/{MoroccoPost,AlgeriaPost,TunisiaPost,KenyaPost,NigeriaPost,EthiopiaPost,GhanaPost,TanzaniaPost,UgandaPost,RwandaPost,ZambiaPost,ZimbabwePost,MozambiquePost,AngolaPost,SenegalPost,IvoryCoastPost,CameroonPost,MauritiusPost,QatarPost,KuwaitPost,BahrainPost}.php`
- Create: `tests/Carriers/{MoroccoPost,AlgeriaPost,TunisiaPost,KenyaPost,NigeriaPost,EthiopiaPost,GhanaPost,TanzaniaPost,UgandaPost,RwandaPost,ZambiaPost,ZimbabwePost,MozambiquePost,AngolaPost,SenegalPost,IvoryCoastPost,CameroonPost,MauritiusPost,QatarPost,KuwaitPost,BahrainPost}Test.php`
- Create: `tests/fixtures/{morocco-post,algeria-post,tunisia-post,kenya-post,nigeria-post,ethiopia-post,ghana-post,tanzania-post,uganda-post,rwanda-post,zambia-post,zimbabwe-post,mozambique-post,angola-post,senegal-post,ivory-coast-post,cameroon-post,mauritius-post,qatar-post,kuwait-post,bahrain-post}/`

- [ ] **Step 1: 派发实施代理 C**（背景运行，21 家）

代理 C 任务书：
1. 按「代理工作说明书」实现 21 个适配器：Barid Al-Maghrib 摩洛哥 `morocco-post`/MoroccoPost（S10 MA）、Algérie Poste 阿尔及利亚 `algeria-post`/AlgeriaPost（DZ）、La Poste Tunisienne 突尼斯 `tunisia-post`/TunisiaPost（TN）、Posta Kenya 肯尼亚 `kenya-post`/KenyaPost（KE）、NIPOST 尼日利亚 `nigeria-post`/NigeriaPost（NG）、Ethiopia Post 埃塞俄比亚 `ethiopia-post`/EthiopiaPost（ET）、Ghana Post 加纳 `ghana-post`/GhanaPost（GH）、Tanzania Post 坦桑尼亚 `tanzania-post`/TanzaniaPost（TZ）、Uganda Post 乌干达 `uganda-post`/UgandaPost（UG）、Rwanda Post 卢旺达 `rwanda-post`/RwandaPost（RW）、Zampost 赞比亚 `zambia-post`/ZambiaPost（ZM）、Zimpost 津巴布韦 `zimbabwe-post`/ZimbabwePost（ZW）、Mozambique Post 莫桑比克 `mozambique-post`/MozambiquePost（MZ）、Correios de Angola 安哥拉 `angola-post`/AngolaPost（AO）、La Poste Sénégalaise 塞内加尔 `senegal-post`/SenegalPost（SN）、La Poste de Côte d'Ivoire 科特迪瓦 `ivory-coast-post`/IvoryCoastPost（CI）、Cameroon Post 喀麦隆 `cameroon-post`/CameroonPost（CM）、Mauritius Post 毛里求斯 `mauritius-post`/MauritiusPost（MU）、Qatar Post 卡塔尔 `qatar-post`/QatarPost（QA）、Kuwait Post 科威特 `kuwait-post`/KuwaitPost（KW）、Bahrain Post 巴林 `bahrain-post`/BahrainPost（BH）
2. VERIFIED-REQUIRED 项（NG）端点可经 config 覆盖；官方文档核实，**禁止编造 URL**
3. 若某家被证实无可验证端点，按规格备用条款替换（JO/LB/OM/IQ/NA/BW/MG/ML/BF/TG）并在汇报中说明
4. 自检 21 个测试文件全绿后返回 DONE

- [ ] **Step 2: 验证代理 C 输出**

Run: `vendor/bin/phpunit tests/Carriers/MoroccoPostTest.php tests/Carriers/AlgeriaPostTest.php tests/Carriers/TunisiaPostTest.php tests/Carriers/KenyaPostTest.php tests/Carriers/NigeriaPostTest.php tests/Carriers/EthiopiaPostTest.php tests/Carriers/GhanaPostTest.php tests/Carriers/TanzaniaPostTest.php tests/Carriers/UgandaPostTest.php tests/Carriers/RwandaPostTest.php tests/Carriers/ZambiaPostTest.php tests/Carriers/ZimbabwePostTest.php tests/Carriers/MozambiquePostTest.php tests/Carriers/AngolaPostTest.php tests/Carriers/SenegalPostTest.php tests/Carriers/IvoryCoastPostTest.php tests/Carriers/CameroonPostTest.php tests/Carriers/MauritiusPostTest.php tests/Carriers/QatarPostTest.php tests/Carriers/KuwaitPostTest.php tests/Carriers/BahrainPostTest.php`
Expected: `OK (147 tests, ...)` 全绿

### Task 5: 代理 D — 亚太剩余 21

**Files（全部新建）：**
- Create: `src/Carriers/International/{BangladeshPost,NepalPost,SriLankaPost,MyanmarPost,CambodiaPost,LaosPost,MongoliaPost,GeorgiaPost,AzerbaijanPost,ArmeniaPost,UzbekistanPost,KyrgyzstanPost,TajikistanPost,TurkmenistanPost,AfghanistanPost,BhutanPost,MaldivesPost,BruneiPost,PapuaPost,FijiPost,SamoaPost}.php`
- Create: `tests/Carriers/{BangladeshPost,NepalPost,SriLankaPost,MyanmarPost,CambodiaPost,LaosPost,MongoliaPost,GeorgiaPost,AzerbaijanPost,ArmeniaPost,UzbekistanPost,KyrgyzstanPost,TajikistanPost,TurkmenistanPost,AfghanistanPost,BhutanPost,MaldivesPost,BruneiPost,PapuaPost,FijiPost,SamoaPost}Test.php`
- Create: `tests/fixtures/{bangladesh-post,nepal-post,sri-lanka-post,myanmar-post,cambodia-post,laos-post,mongolia-post,georgia-post,azerbaijan-post,armenia-post,uzbekistan-post,kyrgyzstan-post,tajikistan-post,turkmenistan-post,afghanistan-post,bhutan-post,maldives-post,brunei-post,papua-post,fiji-post,samoa-post}/`

- [ ] **Step 1: 派发实施代理 D**（背景运行，21 家）

代理 D 任务书：
1. 按「代理工作说明书」实现 21 个适配器：Bangladesh Post 孟加拉 `bangladesh-post`/BangladeshPost（S10 BD）、Nepal Post 尼泊尔 `nepal-post`/NepalPost（NP）、Sri Lanka Post 斯里兰卡 `sri-lanka-post`/SriLankaPost（LK）、Myanmar Post 缅甸 `myanmar-post`/MyanmarPost（MM）、Cambodia Post 柬埔寨 `cambodia-post`/CambodiaPost（KH）、Laos Post 老挝 `laos-post`/LaosPost（LA）、Mongolia Post 蒙古 `mongolia-post`/MongoliaPost（MN）、Georgian Post 格鲁吉亚 `georgia-post`/GeorgiaPost（GE）、Azərpoçt 阿塞拜疆 `azerbaijan-post`/AzerbaijanPost（AZ）、HayPost 亚美尼亚 `armenia-post`/ArmeniaPost（AM）、Uzbekistan Post 乌兹别克斯坦 `uzbekistan-post`/UzbekistanPost（UZ）、Kyrgyz Post 吉尔吉斯斯坦 `kyrgyzstan-post`/KyrgyzstanPost（KG）、Tajikistan Post 塔吉克斯坦 `tajikistan-post`/TajikistanPost（TJ）、Turkmenistan Post 土库曼斯坦 `turkmenistan-post`/TurkmenistanPost（TM）、Afghanistan Post 阿富汗 `afghanistan-post`/AfghanistanPost（AF）、Bhutan Post 不丹 `bhutan-post`/BhutanPost（BT）、Maldives Post 马尔代夫 `maldives-post`/MaldivesPost（MV）、Brunei Post 文莱 `brunei-post`/BruneiPost（BN）、Papua New Guinea Post 巴布亚新几内亚 `papua-post`/PapuaPost（PG）、Fiji Post 斐济 `fiji-post`/FijiPost（FJ）、Samoa Post 萨摩亚 `samoa-post`/SamoaPost（WS）
2. 中亚/西亚多家（UZ/KG/TJ/TM/AF）与西里尔文本注意 mb_strtolower；VERIFIED-REQUIRED 项（MM/TM/AF）端点可经 config 覆盖；官方文档核实，**禁止编造 URL**
3. 若某家被证实无可验证端点，按规格备用条款替换（MO/TO/VU/SB/TL/KI）并在汇报中说明
4. 自检 21 个测试文件全绿后返回 DONE

- [ ] **Step 2: 验证代理 D 输出**

Run: `vendor/bin/phpunit tests/Carriers/BangladeshPostTest.php tests/Carriers/NepalPostTest.php tests/Carriers/SriLankaPostTest.php tests/Carriers/MyanmarPostTest.php tests/Carriers/CambodiaPostTest.php tests/Carriers/LaosPostTest.php tests/Carriers/MongoliaPostTest.php tests/Carriers/GeorgiaPostTest.php tests/Carriers/AzerbaijanPostTest.php tests/Carriers/ArmeniaPostTest.php tests/Carriers/UzbekistanPostTest.php tests/Carriers/KyrgyzstanPostTest.php tests/Carriers/TajikistanPostTest.php tests/Carriers/TurkmenistanPostTest.php tests/Carriers/AfghanistanPostTest.php tests/Carriers/BhutanPostTest.php tests/Carriers/MaldivesPostTest.php tests/Carriers/BruneiPostTest.php tests/Carriers/PapuaPostTest.php tests/Carriers/FijiPostTest.php tests/Carriers/SamoaPostTest.php`
Expected: `OK (147 tests, ...)` 全绿

### Task 6: 主会话注册共享文件

- [ ] **Step 1: 收集 config 键并写入 `config/logistics.php`**

Run: `grep -oh "config->get('[a-z-]*\.[a-z_]*" src/Carriers/International/{DeutschePost,MontenegroPost,AndorraPost,MonacoPost,LiechtensteinPost,SanMarinoPost,VaticanPost,GibraltarPost,JerseyPost,GuernseyPost,IsleOfManPost,FaroePost,GreenlandPost,AlandPost,ColombiaPost,PeruPost,UruguayPost,ParaguayPost,BoliviaPost,EcuadorPost,VenezuelaPost,CostaRicaPost,PanamaPost,DominicanPost,GuatemalaPost,HondurasPost,ElSalvadorPost,NicaraguaPost,CubaPost,JamaicaPost,TrinidadPost,BarbadosPost,BahamasPost,SurinamePost,GuyanaPost,MoroccoPost,AlgeriaPost,TunisiaPost,KenyaPost,NigeriaPost,EthiopiaPost,GhanaPost,TanzaniaPost,UgandaPost,RwandaPost,ZambiaPost,ZimbabwePost,MozambiquePost,AngolaPost,SenegalPost,IvoryCoastPost,CameroonPost,MauritiusPost,QatarPost,KuwaitPost,BahrainPost,BangladeshPost,NepalPost,SriLankaPost,MyanmarPost,CambodiaPost,LaosPost,MongoliaPost,GeorgiaPost,AzerbaijanPost,ArmeniaPost,UzbekistanPost,KyrgyzstanPost,TajikistanPost,TurkmenistanPost,AfghanistanPost,BhutanPost,MaldivesPost,BruneiPost,PapuaPost,FijiPost,SamoaPost}.php | sed "s/config->get('//" | sort -u`
按结果在 `config/logistics.php` 为 77 家各加一行注释键数组（如 `'morocco-post' => ['endpoint' => '']`，有认证键的加 `'key' => ''`）

- [ ] **Step 2: 注册 `src/Resources/carrier-registry.php`**

- use 语句（国际块末尾，即 `use GlobalLogistics\Carriers\International\BosniaPost;` 之后追加，内部字母序）77 条，如 `use GlobalLogistics\Carriers\International\DeutschePost;` ...（按实际类名逐一列出）
- 国际条目（`'bosnia-post' => BosniaPost::class,` 之后追加）77 条，如 `'deutsche-post' => DeutschePost::class, 'montenegro-post' => MontenegroPost::class, ...`（slug 与规格清单逐一对应）

- [ ] **Step 3: 检测规则 `src/Resources/detector-rules.php`**

在 `/^[A-Z]{2}\d{9}BA$/i`（bosnia-post）与燕文规则 `/^[A-Z]{2}\d{9}(YP|YW|YE|YL)$/i` 之间插入 78 条（国家码互不重复且与现有 62 个国家码不重叠，无相互冲突；全部在通用 FedEx 规则之前）：

```php
// A 欧洲剩余（15，含 NL 复用）
'/^[A-Z]{2}\d{9}DE$/i' => ['international', 'deutsche-post'], // 德国 Deutsche Post，同上
'/^[A-Z]{2}\d{9}ME$/i' => ['international', 'montenegro-post'], // 黑山 Montenegro Post，同上
'/^[A-Z]{2}\d{9}AD$/i' => ['international', 'andorra-post'], // 安道尔 Andorra Post，同上
'/^[A-Z]{2}\d{9}MC$/i' => ['international', 'monaco-post'], // 摩纳哥 La Poste Monaco，同上
'/^[A-Z]{2}\d{9}LI$/i' => ['international', 'liechtenstein-post'], // 列支敦士登 Liechtenstein Post，同上
'/^[A-Z]{2}\d{9}SM$/i' => ['international', 'san-marino-post'], // 圣马力诺 Poste San Marino，同上
'/^[A-Z]{2}\d{9}VA$/i' => ['international', 'vatican-post'], // 梵蒂冈 Poste Vaticane，同上
'/^[A-Z]{2}\d{9}GI$/i' => ['international', 'gibraltar-post'], // 直布罗陀 Royal Gibraltar Post，同上
'/^[A-Z]{2}\d{9}JE$/i' => ['international', 'jersey-post'], // 泽西 Jersey Post，同上
'/^[A-Z]{2}\d{9}GG$/i' => ['international', 'guernsey-post'], // 根西 Guernsey Post，同上
'/^[A-Z]{2}\d{9}IM$/i' => ['international', 'isle-of-man-post'], // 马恩岛 Isle of Man Post，同上
'/^[A-Z]{2}\d{9}FO$/i' => ['international', 'faroe-post'], // 法罗群岛 Posta Faroe Islands，同上
'/^[A-Z]{2}\d{9}GL$/i' => ['international', 'greenland-post'], // 格陵兰 Post Greenland，同上
'/^[A-Z]{2}\d{9}AX$/i' => ['international', 'aland-post'], // 奥兰群岛 Post Åland，同上
'/^[A-Z]{2}\d{9}NL$/i' => ['international', 'postnl'], // 荷兰 PostNL（复用既有适配器），同上
// B 拉美加勒比（21）
'/^[A-Z]{2}\d{9}CO$/i' => ['international', 'colombia-post'], // 哥伦比亚 4-72，同上
'/^[A-Z]{2}\d{9}PE$/i' => ['international', 'peru-post'], // 秘鲁 Serpost，同上
'/^[A-Z]{2}\d{9}UY$/i' => ['international', 'uruguay-post'], // 乌拉圭 Correo Uruguayo，同上
'/^[A-Z]{2}\d{9}PY$/i' => ['international', 'paraguay-post'], // 巴拉圭 Correo Paraguayo，同上
'/^[A-Z]{2}\d{9}BO$/i' => ['international', 'bolivia-post'], // 玻利维亚 Correos de Bolivia，同上
'/^[A-Z]{2}\d{9}EC$/i' => ['international', 'ecuador-post'], // 厄瓜多尔 Correos del Ecuador，同上
'/^[A-Z]{2}\d{9}VE$/i' => ['international', 'venezuela-post'], // 委内瑞拉 Ipostel，同上
'/^[A-Z]{2}\d{9}CR$/i' => ['international', 'costa-rica-post'], // 哥斯达黎加 Correos de Costa Rica，同上
'/^[A-Z]{2}\d{9}PA$/i' => ['international', 'panama-post'], // 巴拿马 Correos de Panamá，同上
'/^[A-Z]{2}\d{9}DO$/i' => ['international', 'dominican-post'], // 多米尼加 INPOSDOM，同上
'/^[A-Z]{2}\d{9}GT$/i' => ['international', 'guatemala-post'], // 危地马拉 Correo de Guatemala，同上
'/^[A-Z]{2}\d{9}HN$/i' => ['international', 'honduras-post'], // 洪都拉斯 HonduCorreo，同上
'/^[A-Z]{2}\d{9}SV$/i' => ['international', 'el-salvador-post'], // 萨尔瓦多 Correos de El Salvador，同上
'/^[A-Z]{2}\d{9}NI$/i' => ['international', 'nicaragua-post'], // 尼加拉瓜 Correos de Nicaragua，同上
'/^[A-Z]{2}\d{9}CU$/i' => ['international', 'cuba-post'], // 古巴 Correos de Cuba，同上
'/^[A-Z]{2}\d{9}JM$/i' => ['international', 'jamaica-post'], // 牙买加 Jamaica Post，同上
'/^[A-Z]{2}\d{9}TT$/i' => ['international', 'trinidad-post'], // 特立尼达和多巴哥 TTPOST，同上
'/^[A-Z]{2}\d{9}BB$/i' => ['international', 'barbados-post'], // 巴巴多斯 Barbados Post，同上
'/^[A-Z]{2}\d{9}BS$/i' => ['international', 'bahamas-post'], // 巴哈马 Bahamas Post，同上
'/^[A-Z]{2}\d{9}SR$/i' => ['international', 'suriname-post'], // 苏里南 Suriname Post，同上
'/^[A-Z]{2}\d{9}GY$/i' => ['international', 'guyana-post'], // 圭亚那 Guyana Post，同上
// C 非洲中东（21）
'/^[A-Z]{2}\d{9}MA$/i' => ['international', 'morocco-post'], // 摩洛哥 Barid Al-Maghrib，同上
'/^[A-Z]{2}\d{9}DZ$/i' => ['international', 'algeria-post'], // 阿尔及利亚 Algérie Poste，同上
'/^[A-Z]{2}\d{9}TN$/i' => ['international', 'tunisia-post'], // 突尼斯 La Poste Tunisienne，同上
'/^[A-Z]{2}\d{9}KE$/i' => ['international', 'kenya-post'], // 肯尼亚 Posta Kenya，同上
'/^[A-Z]{2}\d{9}NG$/i' => ['international', 'nigeria-post'], // 尼日利亚 NIPOST，同上
'/^[A-Z]{2}\d{9}ET$/i' => ['international', 'ethiopia-post'], // 埃塞俄比亚 Ethiopia Post，同上
'/^[A-Z]{2}\d{9}GH$/i' => ['international', 'ghana-post'], // 加纳 Ghana Post，同上
'/^[A-Z]{2}\d{9}TZ$/i' => ['international', 'tanzania-post'], // 坦桑尼亚 Tanzania Post，同上
'/^[A-Z]{2}\d{9}UG$/i' => ['international', 'uganda-post'], // 乌干达 Uganda Post，同上
'/^[A-Z]{2}\d{9}RW$/i' => ['international', 'rwanda-post'], // 卢旺达 Rwanda Post，同上
'/^[A-Z]{2}\d{9}ZM$/i' => ['international', 'zambia-post'], // 赞比亚 Zampost，同上
'/^[A-Z]{2}\d{9}ZW$/i' => ['international', 'zimbabwe-post'], // 津巴布韦 Zimpost，同上
'/^[A-Z]{2}\d{9}MZ$/i' => ['international', 'mozambique-post'], // 莫桑比克 Mozambique Post，同上
'/^[A-Z]{2}\d{9}AO$/i' => ['international', 'angola-post'], // 安哥拉 Correios de Angola，同上
'/^[A-Z]{2}\d{9}SN$/i' => ['international', 'senegal-post'], // 塞内加尔 La Poste Sénégalaise，同上
'/^[A-Z]{2}\d{9}CI$/i' => ['international', 'ivory-coast-post'], // 科特迪瓦 La Poste de Côte d'Ivoire，同上
'/^[A-Z]{2}\d{9}CM$/i' => ['international', 'cameroon-post'], // 喀麦隆 Cameroon Post，同上
'/^[A-Z]{2}\d{9}MU$/i' => ['international', 'mauritius-post'], // 毛里求斯 Mauritius Post，同上
'/^[A-Z]{2}\d{9}QA$/i' => ['international', 'qatar-post'], // 卡塔尔 Qatar Post，同上
'/^[A-Z]{2}\d{9}KW$/i' => ['international', 'kuwait-post'], // 科威特 Kuwait Post，同上
'/^[A-Z]{2}\d{9}BH$/i' => ['international', 'bahrain-post'], // 巴林 Bahrain Post，同上
// D 亚太剩余（21）
'/^[A-Z]{2}\d{9}BD$/i' => ['international', 'bangladesh-post'], // 孟加拉 Bangladesh Post，同上
'/^[A-Z]{2}\d{9}NP$/i' => ['international', 'nepal-post'], // 尼泊尔 Nepal Post，同上
'/^[A-Z]{2}\d{9}LK$/i' => ['international', 'sri-lanka-post'], // 斯里兰卡 Sri Lanka Post，同上
'/^[A-Z]{2}\d{9}MM$/i' => ['international', 'myanmar-post'], // 缅甸 Myanmar Post，同上
'/^[A-Z]{2}\d{9}KH$/i' => ['international', 'cambodia-post'], // 柬埔寨 Cambodia Post，同上
'/^[A-Z]{2}\d{9}LA$/i' => ['international', 'laos-post'], // 老挝 Laos Post，同上
'/^[A-Z]{2}\d{9}MN$/i' => ['international', 'mongolia-post'], // 蒙古 Mongolia Post，同上
'/^[A-Z]{2}\d{9}GE$/i' => ['international', 'georgia-post'], // 格鲁吉亚 Georgian Post，同上
'/^[A-Z]{2}\d{9}AZ$/i' => ['international', 'azerbaijan-post'], // 阿塞拜疆 Azərpoçt，同上
'/^[A-Z]{2}\d{9}AM$/i' => ['international', 'armenia-post'], // 亚美尼亚 HayPost，同上
'/^[A-Z]{2}\d{9}UZ$/i' => ['international', 'uzbekistan-post'], // 乌兹别克斯坦 Uzbekistan Post，同上
'/^[A-Z]{2}\d{9}KG$/i' => ['international', 'kyrgyzstan-post'], // 吉尔吉斯斯坦 Kyrgyz Post，同上
'/^[A-Z]{2}\d{9}TJ$/i' => ['international', 'tajikistan-post'], // 塔吉克斯坦 Tajikistan Post，同上
'/^[A-Z]{2}\d{9}TM$/i' => ['international', 'turkmenistan-post'], // 土库曼斯坦 Turkmenistan Post，同上
'/^[A-Z]{2}\d{9}AF$/i' => ['international', 'afghanistan-post'], // 阿富汗 Afghanistan Post，同上
'/^[A-Z]{2}\d{9}BT$/i' => ['international', 'bhutan-post'], // 不丹 Bhutan Post，同上
'/^[A-Z]{2}\d{9}MV$/i' => ['international', 'maldives-post'], // 马尔代夫 Maldives Post，同上
'/^[A-Z]{2}\d{9}BN$/i' => ['international', 'brunei-post'], // 文莱 Brunei Post，同上
'/^[A-Z]{2}\d{9}PG$/i' => ['international', 'papua-post'], // 巴布亚新几内亚 Papua New Guinea Post，同上
'/^[A-Z]{2}\d{9}FJ$/i' => ['international', 'fiji-post'], // 斐济 Fiji Post，同上
'/^[A-Z]{2}\d{9}WS$/i' => ['international', 'samoa-post'], // 萨摩亚 Samoa Post，同上
```

- [ ] **Step 4: `tests/Unit/DetectorTest.php` 新增 78 个用例**

在 `testDetectsBosniaPostBeforeGenericFedExRule` 之后插入（样式同现有 S10 用例，`Detector::withDefaults()` + `detect()` + 断言 `Channel::International` 与承运商代码）；样例单号统一 `RA123456789XX`，期望代码与 Step 3 规则一一对应：

| 测试方法 | 国家码 | 期望代码 |
|---|---|---|
| testDetectsDeutschePostBeforeGenericFedExRule | DE | deutsche-post |
| testDetectsMontenegroPostBeforeGenericFedExRule | ME | montenegro-post |
| testDetectsAndorraPostBeforeGenericFedExRule | AD | andorra-post |
| testDetectsMonacoPostBeforeGenericFedExRule | MC | monaco-post |
| testDetectsLiechtensteinPostBeforeGenericFedExRule | LI | liechtenstein-post |
| testDetectsSanMarinoPostBeforeGenericFedExRule | SM | san-marino-post |
| testDetectsVaticanPostBeforeGenericFedExRule | VA | vatican-post |
| testDetectsGibraltarPostBeforeGenericFedExRule | GI | gibraltar-post |
| testDetectsJerseyPostBeforeGenericFedExRule | JE | jersey-post |
| testDetectsGuernseyPostBeforeGenericFedExRule | GG | guernsey-post |
| testDetectsIsleOfManPostBeforeGenericFedExRule | IM | isle-of-man-post |
| testDetectsFaroePostBeforeGenericFedExRule | FO | faroe-post |
| testDetectsGreenlandPostBeforeGenericFedExRule | GL | greenland-post |
| testDetectsAlandPostBeforeGenericFedExRule | AX | aland-post |
| testDetectsPostNlCountryCodeBeforeGenericFedExRule | NL | postnl |
| testDetectsColombiaPostBeforeGenericFedExRule | CO | colombia-post |
| testDetectsPeruPostBeforeGenericFedExRule | PE | peru-post |
| testDetectsUruguayPostBeforeGenericFedExRule | UY | uruguay-post |
| testDetectsParaguayPostBeforeGenericFedExRule | PY | paraguay-post |
| testDetectsBoliviaPostBeforeGenericFedExRule | BO | bolivia-post |
| testDetectsEcuadorPostBeforeGenericFedExRule | EC | ecuador-post |
| testDetectsVenezuelaPostBeforeGenericFedExRule | VE | venezuela-post |
| testDetectsCostaRicaPostBeforeGenericFedExRule | CR | costa-rica-post |
| testDetectsPanamaPostBeforeGenericFedExRule | PA | panama-post |
| testDetectsDominicanPostBeforeGenericFedExRule | DO | dominican-post |
| testDetectsGuatemalaPostBeforeGenericFedExRule | GT | guatemala-post |
| testDetectsHondurasPostBeforeGenericFedExRule | HN | honduras-post |
| testDetectsElSalvadorPostBeforeGenericFedExRule | SV | el-salvador-post |
| testDetectsNicaraguaPostBeforeGenericFedExRule | NI | nicaragua-post |
| testDetectsCubaPostBeforeGenericFedExRule | CU | cuba-post |
| testDetectsJamaicaPostBeforeGenericFedExRule | JM | jamaica-post |
| testDetectsTrinidadPostBeforeGenericFedExRule | TT | trinidad-post |
| testDetectsBarbadosPostBeforeGenericFedExRule | BB | barbados-post |
| testDetectsBahamasPostBeforeGenericFedExRule | BS | bahamas-post |
| testDetectsSurinamePostBeforeGenericFedExRule | SR | suriname-post |
| testDetectsGuyanaPostBeforeGenericFedExRule | GY | guyana-post |
| testDetectsMoroccoPostBeforeGenericFedExRule | MA | morocco-post |
| testDetectsAlgeriaPostBeforeGenericFedExRule | DZ | algeria-post |
| testDetectsTunisiaPostBeforeGenericFedExRule | TN | tunisia-post |
| testDetectsKenyaPostBeforeGenericFedExRule | KE | kenya-post |
| testDetectsNigeriaPostBeforeGenericFedExRule | NG | nigeria-post |
| testDetectsEthiopiaPostBeforeGenericFedExRule | ET | ethiopia-post |
| testDetectsGhanaPostBeforeGenericFedExRule | GH | ghana-post |
| testDetectsTanzaniaPostBeforeGenericFedExRule | TZ | tanzania-post |
| testDetectsUgandaPostBeforeGenericFedExRule | UG | uganda-post |
| testDetectsRwandaPostBeforeGenericFedExRule | RW | rwanda-post |
| testDetectsZambiaPostBeforeGenericFedExRule | ZM | zambia-post |
| testDetectsZimbabwePostBeforeGenericFedExRule | ZW | zimbabwe-post |
| testDetectsMozambiquePostBeforeGenericFedExRule | MZ | mozambique-post |
| testDetectsAngolaPostBeforeGenericFedExRule | AO | angola-post |
| testDetectsSenegalPostBeforeGenericFedExRule | SN | senegal-post |
| testDetectsIvoryCoastPostBeforeGenericFedExRule | CI | ivory-coast-post |
| testDetectsCameroonPostBeforeGenericFedExRule | CM | cameroon-post |
| testDetectsMauritiusPostBeforeGenericFedExRule | MU | mauritius-post |
| testDetectsQatarPostBeforeGenericFedExRule | QA | qatar-post |
| testDetectsKuwaitPostBeforeGenericFedExRule | KW | kuwait-post |
| testDetectsBahrainPostBeforeGenericFedExRule | BH | bahrain-post |
| testDetectsBangladeshPostBeforeGenericFedExRule | BD | bangladesh-post |
| testDetectsNepalPostBeforeGenericFedExRule | NP | nepal-post |
| testDetectsSriLankaPostBeforeGenericFedExRule | LK | sri-lanka-post |
| testDetectsMyanmarPostBeforeGenericFedExRule | MM | myanmar-post |
| testDetectsCambodiaPostBeforeGenericFedExRule | KH | cambodia-post |
| testDetectsLaosPostBeforeGenericFedExRule | LA | laos-post |
| testDetectsMongoliaPostBeforeGenericFedExRule | MN | mongolia-post |
| testDetectsGeorgiaPostBeforeGenericFedExRule | GE | georgia-post |
| testDetectsAzerbaijanPostBeforeGenericFedExRule | AZ | azerbaijan-post |
| testDetectsArmeniaPostBeforeGenericFedExRule | AM | armenia-post |
| testDetectsUzbekistanPostBeforeGenericFedExRule | UZ | uzbekistan-post |
| testDetectsKyrgyzstanPostBeforeGenericFedExRule | KG | kyrgyzstan-post |
| testDetectsTajikistanPostBeforeGenericFedExRule | TJ | tajikistan-post |
| testDetectsTurkmenistanPostBeforeGenericFedExRule | TM | turkmenistan-post |
| testDetectsAfghanistanPostBeforeGenericFedExRule | AF | afghanistan-post |
| testDetectsBhutanPostBeforeGenericFedExRule | BT | bhutan-post |
| testDetectsMaldivesPostBeforeGenericFedExRule | MV | maldives-post |
| testDetectsBruneiPostBeforeGenericFedExRule | BN | brunei-post |
| testDetectsPapuaPostBeforeGenericFedExRule | PG | papua-post |
| testDetectsFijiPostBeforeGenericFedExRule | FJ | fiji-post |
| testDetectsSamoaPostBeforeGenericFedExRule | WS | samoa-post |

- [ ] **Step 5: 冒烟断言 132 → 209**

Modify: `tests/Unit/CarrierRegistrySmokeTest.php:31` → `$this->assertSame(209, $count);`

- [ ] **Step 6: `README.md`**

- 「已接入承运商（132 家）」→ 209 家；「109 条正则规则」→ 187
- 国际表追加 77 行（按代理 A/B/C/D 顺序）：Deutsche Post（德国）`deutsche-post` / Montenegro Post（黑山）`montenegro-post` / Andorra Post（安道尔）`andorra-post` / La Poste Monaco（摩纳哥）`monaco-post` / Liechtenstein Post（列支敦士登）`liechtenstein-post` / Poste San Marino（圣马力诺）`san-marino-post` / Poste Vaticane（梵蒂冈）`vatican-post` / Royal Gibraltar Post（直布罗陀）`gibraltar-post` / Jersey Post（泽西）`jersey-post` / Guernsey Post（根西）`guernsey-post` / Isle of Man Post（马恩岛）`isle-of-man-post` / Posta Faroe Islands（法罗群岛）`faroe-post` / Post Greenland（格陵兰）`greenland-post` / Post Åland（奥兰群岛）`aland-post` / 4-72（哥伦比亚）`colombia-post` / Serpost（秘鲁）`peru-post` / Correo Uruguayo（乌拉圭）`uruguay-post` / Correo Paraguayo（巴拉圭）`paraguay-post` / Correos de Bolivia（玻利维亚）`bolivia-post` / Correos del Ecuador（厄瓜多尔）`ecuador-post` / Ipostel（委内瑞拉）`venezuela-post` / Correos de Costa Rica（哥斯达黎加）`costa-rica-post` / Correos de Panamá（巴拿马）`panama-post` / INPOSDOM（多米尼加）`dominican-post` / Correo de Guatemala（危地马拉）`guatemala-post` / HonduCorreo（洪都拉斯）`honduras-post` / Correos de El Salvador（萨尔瓦多）`el-salvador-post` / Correos de Nicaragua（尼加拉瓜）`nicaragua-post` / Correos de Cuba（古巴）`cuba-post` / Jamaica Post（牙买加）`jamaica-post` / TTPOST（特立尼达和多巴哥）`trinidad-post` / Barbados Post（巴巴多斯）`barbados-post` / Bahamas Post（巴哈马）`bahamas-post` / Suriname Post（苏里南）`suriname-post` / Guyana Post（圭亚那）`guyana-post` / Barid Al-Maghrib（摩洛哥）`morocco-post` / Algérie Poste（阿尔及利亚）`algeria-post` / La Poste Tunisienne（突尼斯）`tunisia-post` / Posta Kenya（肯尼亚）`kenya-post` / NIPOST（尼日利亚）`nigeria-post` / Ethiopia Post（埃塞俄比亚）`ethiopia-post` / Ghana Post（加纳）`ghana-post` / Tanzania Post（坦桑尼亚）`tanzania-post` / Uganda Post（乌干达）`uganda-post` / Rwanda Post（卢旺达）`rwanda-post` / Zampost（赞比亚）`zambia-post` / Zimpost（津巴布韦）`zimbabwe-post` / Mozambique Post（莫桑比克）`mozambique-post` / Correios de Angola（安哥拉）`angola-post` / La Poste Sénégalaise（塞内加尔）`senegal-post` / La Poste de Côte d'Ivoire（科特迪瓦）`ivory-coast-post` / Cameroon Post（喀麦隆）`cameroon-post` / Mauritius Post（毛里求斯）`mauritius-post` / Qatar Post（卡塔尔）`qatar-post` / Kuwait Post（科威特）`kuwait-post` / Bahrain Post（巴林）`bahrain-post` / Bangladesh Post（孟加拉）`bangladesh-post` / Nepal Post（尼泊尔）`nepal-post` / Sri Lanka Post（斯里兰卡）`sri-lanka-post` / Myanmar Post（缅甸）`myanmar-post` / Cambodia Post（柬埔寨）`cambodia-post` / Laos Post（老挝）`laos-post` / Mongolia Post（蒙古）`mongolia-post` / Georgian Post（格鲁吉亚）`georgia-post` / Azərpoçt（阿塞拜疆）`azerbaijan-post` / HayPost（亚美尼亚）`armenia-post` / Uzbekistan Post（乌兹别克斯坦）`uzbekistan-post` / Kyrgyz Post（吉尔吉斯斯坦）`kyrgyzstan-post` / Tajikistan Post（塔吉克斯坦）`tajikistan-post` / Turkmenistan Post（土库曼斯坦）`turkmenistan-post` / Afghanistan Post（阿富汗）`afghanistan-post` / Bhutan Post（不丹）`bhutan-post` / Maldives Post（马尔代夫）`maldives-post` / Brunei Post（文莱）`brunei-post` / Papua New Guinea Post（巴布亚新几内亚）`papua-post` / Fiji Post（斐济）`fiji-post` / Samoa Post（萨摩亚）`samoa-post`

- [ ] **Step 7: 局部验证**

Run: `vendor/bin/phpunit tests/Unit/DetectorTest.php tests/Unit/CarrierRegistrySmokeTest.php`
Expected: `OK (186 tests, ...)`（DetectorTest 185 + 冒烟 1）

### Task 7: 全量验证 + 提交

- [ ] **Step 1: 全量测试**

Run: `vendor/bin/phpunit`
Expected: `OK (1663 tests, ...)`（1046 基线 + 77×7 新适配器 539 + 78 新检测用例），全绿

- [ ] **Step 2: 提交**

```bash
git add README.md config/logistics.php src/Resources/carrier-registry.php src/Resources/detector-rules.php tests/Unit/CarrierRegistrySmokeTest.php tests/Unit/DetectorTest.php docs/superpowers/plans/2026-08-15-wave8-four-regions.md src/Carriers/International/ tests/Carriers/ tests/fixtures/
git commit -m "$(cat <<'EOF'
feat: 接入 77 家新承运商（国际 S10 四区域），共 209 家

欧洲剩余：Deutsche Post、Montenegro Post、Andorra Post、La Poste Monaco、
Liechtenstein Post、Poste San Marino、Poste Vaticane、Royal Gibraltar Post、
Jersey Post、Guernsey Post、Isle of Man Post、Posta Faroe Islands、
Post Greenland、Post Åland
拉美加勒比：4-72、Serpost、Correo Uruguayo、Correo Paraguayo、
Correos de Bolivia、Correos del Ecuador、Ipostel、Correos de Costa Rica、
Correos de Panamá、INPOSDOM、Correo de Guatemala、HonduCorreo、
Correos de El Salvador、Correos de Nicaragua、Correos de Cuba、
Jamaica Post、TTPOST、Barbados Post、Bahamas Post、Suriname Post、Guyana Post
非洲中东：Barid Al-Maghrib、Algérie Poste、La Poste Tunisienne、Posta Kenya、
NIPOST、Ethiopia Post、Ghana Post、Tanzania Post、Uganda Post、Rwanda Post、
Zampost、Zimpost、Mozambique Post、Correios de Angola、La Poste Sénégalaise、
La Poste de Côte d'Ivoire、Cameroon Post、Mauritius Post、Qatar Post、
Kuwait Post、Bahrain Post
亚太剩余：Bangladesh Post、Nepal Post、Sri Lanka Post、Myanmar Post、
Cambodia Post、Laos Post、Mongolia Post、Georgian Post、Azərpoçt、HayPost、
Uzbekistan Post、Kyrgyz Post、Tajikistan Post、Turkmenistan Post、
Afghanistan Post、Bhutan Post、Maldives Post、Brunei Post、
Papua New Guinea Post、Fiji Post、Samoa Post
EOF
)"
```

- [ ] **Step 3: 验证工作区干净**

Run: `git status --short`
Expected: 空输出（无未提交/未跟踪文件）

## 自检

- 规格覆盖：77 家清单（Task 2/3/4/5）✓、NL 规则复用（Task 6 Step 3）✓、共享文件注册（Task 6）✓、S10 规则 78 条置于 FedEx 规则前（Step 3）✓、备用替换条款（各代理任务书 Step 1 第 3 条）✓、冒烟 209（Step 5）✓、提交信息（Task 7）✓
- 占位符扫描：无 TBD/TODO；每家适配器/测试/夹具路径精确给出
- 类型一致性：类名/代码/国家码三表（规格、Task 2/3/4/5、Task 6）逐一核对一致；`{slug}` 与测试类名、fixtures 目录名一致；S10 正则与国家码一致；78 个国家码（DE ME AD MC LI SM VA GI JE GG IM FO GL AX NL CO PE UY PY BO EC VE CR PA DO GT HN SV NI CU JM TT BB BS SR GY MA DZ TN KE NG ET GH TZ UG RW ZM ZW MZ AO SN CI CM MU QA KW BH BD NP LK MM KH LA MN GE AZ AM UZ KG TJ TM AF BT MV BN PG FJ WS）与现有 62 个国家码规则逐一比对无重复（已核对现有：GB JP BR HK KR FR NZ IT RU SG CH BE ES AT NO TH TW EE FI SE DK PT IE PL IN MY AE HU CZ GR VN UA TR IL EG SA ZA MX AR CL ID PH PK KZ RO HR SK SI RS BG LT LV MD AL MT LU IS CY BY MK BA）
