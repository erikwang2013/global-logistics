<?php

declare(strict_types=1);

/*
 * global-logistics 统一配置模板（Laravel / ThinkPHP / Hyperf / Webman 共享）。
 *
 * 用法：
 *   1. 将本文件复制到框架配置目录（Laravel: config/logistics.php，
 *      Hyperf: config/autoload/logistics.php，ThinkPHP/Yii: 应用 config/logistics.php，
 *      Webman: config/plugin/erikwang2013/global-logistics/），或直接传给
 *      Logistics::configure($array)。
 *   2. 顶层键 = 承运商代码，结构同 Logistics::configure() 入参。
 *   3. 只填写你要使用的承运商即可，其余留空或删除均不影响。
 *   4. 密钥属于敏感信息：请通过环境变量或框架的 .env 注入，切勿硬编码进代码仓库。
 *
 * 字段速查（各承运商只需填本文件中列出的字段）：
 *   - partner_id / company_id / app_id / user_id / key / api_key / service_key
 *      各家分配的账号或密钥标识
 *   - checkword / secret / app_secret / client_secret / password / token
 *      签名密钥或口令（不同承运商命名不同，含义一致：请求签名/鉴权用）
 *   - client_id / client_secret / access_token / subscription_key
 *      OAuth2 / 订阅制承运商（如 DHL、FedEx、UPS、Royal Mail、Swiss Post、Yodel）
 *   - endpoint（可选）自定义 API 端点，留空 '' 则使用内置官方端点
 *   - 无认证承运商（如 japan-post、多数邮政）留空数组或仅填可选字段即可
 *
 * 更多说明见 README.md「使用说明 - 配置」章节。
 */

return [
    // 可选：自定义 PSR-18 HTTP 客户端实例（null 则自动构建 Guzzle）
    'http_client' => null,

    // 可选：单次请求失败后的重试次数（默认 2）
    'max_retries' => 2,

    /*
     * 国内快递（45 家）
     * 字段含义：
     *   partner_id + checkword   顺丰类签名（sf）
     *   company_id + secret      中通类（zto / zto-freight）
     *   app_key + app_secret     圆通、韵达、极兔、德邦等通用签名
     *   ebusiness_id + app_key   快递鸟接口类（lht / rrs / sure / xf / 快运类）
     *   endpoint                 可选自定义接口地址，留空用官方默认
     * 申通(sto)、京东(jd) 无需密钥，留空数组即可。
     */
    // 国内
    'sf' => ['partner_id' => '', 'checkword' => ''],
    'zto' => ['company_id' => '', 'secret' => ''],
    'yto' => ['app_key' => '', 'app_secret' => ''],
    'jt' => ['api_key' => '', 'secret' => ''],
    'yd' => ['app_key' => '', 'app_secret' => ''],
    'sto' => [],
    'jd' => [],
    'ems' => ['app_id' => ''],
    'ht' => ['partner_id' => '', 'token' => ''],
    'debon' => ['app_key' => '', 'app_secret' => ''],
    'ky' => ['app_key' => '', 'app_secret' => ''],
    'lht' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'rrs' => ['key_value' => '', 'source' => '', 'notifyid' => '', 'endpoint' => ''],
    'sure' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'xf' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'ane' => ['app_key' => ''],
    'cainiao' => ['logistic_provider_id' => '', 'secret_key' => ''],
    'china-post' => ['app_id' => '', 'app_secret' => ''],
    'suning' => ['app_key' => '', 'app_secret' => '', 'version_no' => ''],
    'uc' => ['partner_id' => '', 'token' => ''],
    'ymd' => ['partner_id' => '', 'token' => ''],
    'zjs' => ['partner_id' => '', 'token' => ''],
    'tiantian' => ['partner_id' => '', 'token' => '', 'endpoint' => ''],
    'zto-freight' => ['company_id' => '', 'app_secret' => '', 'phone_suffix' => '', 'endpoint' => ''],
    'dainiao' => ['logistic_provider_id' => '', 'secret_key' => '', 'endpoint' => ''],
    'cre' => ['partner_id' => '', 'token' => '', 'phone_suffix' => '', 'endpoint' => ''],
    'sxjd' => ['app_key' => '', 'customer_code' => '', 'endpoint' => ''],
    'fengwang' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'ht-freight' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'yd-freight' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'yto-freight' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'zy' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'cae' => ['ebusiness_id' => '', 'app_key' => '', 'endpoint' => ''],
    'huayu' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'jiaji' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'longbang' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'qy' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'suteng' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'zhongtie' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'zhongyou' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'zengyi' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'quanfeng' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'guotong' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'yuancheng' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],
    'xinbang' => ['ebusiness_id' => '', 'app_key' => '', 'customer_name' => '', 'endpoint' => ''],

    /*
     * 国际物流（164 家），认证方式分三类：
     *   - OAuth2：client_id + client_secret（dhl / fedex / ups / royal-mail /
     *     swiss-post / yodel），token 自动获取、进程内缓存、401 自动刷新
     *   - 签名/密钥认证：各家分配的 api_key、user_id、password 等字段
     *   - 无认证公开 API：多数邮政（japan-post、turkey-post、israel-post、
     *     egypt-post、south-african-post 等），留空数组或仅填可选字段即可
     * endpoint 均为可选字段：留空 '' 使用内置官方端点，可覆盖为代理地址。
     */
    // 国际（OAuth2、签名认证或无认证公开 API）
    'dhl' => ['client_id' => '', 'client_secret' => ''],
    'fedex' => ['client_id' => '', 'client_secret' => ''],
    'ups' => ['client_id' => '', 'client_secret' => ''],
    'usps' => ['user_id' => ''],
    'royal-mail' => ['client_id' => '', 'client_secret' => ''],
    'canada-post' => ['customer_number' => '', 'api_key' => ''],
    'australia-post' => ['api_key' => ''],
    'austrian-post' => ['user_name' => '', 'password' => '', 'endpoint' => ''],
    'bring' => ['api_key' => '', 'client_url' => '', 'endpoint' => ''],
    'chunghwa-post' => ['endpoint' => ''],
    'delhivery' => ['key' => '', 'endpoint' => ''],
    'inpost' => ['endpoint' => ''],
    'omniva' => ['endpoint' => ''],
    'posti' => ['endpoint' => ''],
    'japan-post' => [],
    'aramex' => ['user_name' => '', 'password' => '', 'account_number' => ''],
    'gls' => ['api_key' => ''],
    'dpd' => ['user_name' => '', 'password' => ''],
    'postnl' => ['api_key' => ''],
    'cainiao-intl' => ['endpoint' => ''],
    'correios' => ['user' => '', 'password' => ''],
    'evri' => ['api_key' => '', 'endpoint' => ''],
    'fourpx' => ['app_key' => '', 'app_secret' => '', 'access_token' => ''],
    'hong-kong-post' => ['hkp_id' => '', 'ecship_username' => '', 'integrator_username' => ''],
    'kerry' => ['app_id' => '', 'app_key' => '', 'base_url' => ''],
    'korea-post' => ['service_key' => ''],
    'la-poste' => ['api_key' => ''],
    'nz-post' => ['license_key' => '', 'user_ip_address' => ''],
    'poste-italiane' => ['endpoint' => ''],
    'russia-post' => ['login' => '', 'password' => ''],
    'singapore-post' => ['api_key' => ''],
    'thailand-post' => ['app_token' => '', 'language' => '', 'endpoint' => ''],
    'swiss-post' => ['client_id' => '', 'client_secret' => '', 'scope' => '', 'language' => ''],
    'yodel' => ['client_id' => '', 'client_secret' => '', 'base_url' => '', 'token_url' => ''],
    'yunexpress' => ['app_id' => '', 'app_secret' => '', 'source_key' => ''],
    'yanwen' => ['customer_code' => '', 'api_secret' => '', 'endpoint' => ''],
    'sf-international' => ['partner_id' => '', 'checkword' => '', 'endpoint' => ''],
    'tnt' => ['company_id' => '', 'password' => '', 'endpoint' => ''],
    'ontrac' => ['account_no' => '', 'password' => '', 'endpoint' => ''],
    'purolator' => ['production_key' => '', 'password' => '', 'group_id' => '', 'language' => '', 'endpoint' => ''],
    'bpost' => ['account_id' => '', 'password' => '', 'endpoint' => ''],
    'correos' => ['client_id' => '', 'client_secret' => '', 'endpoint' => ''],
    'postnord' => ['api_key' => '', 'locale' => '', 'endpoint' => ''],
    'ctt' => ['csrf_token' => '', 'cookie' => '', 'api_version' => '', 'endpoint' => ''],
    'an-post' => ['subscription_key' => '', 'endpoint' => ''],
    'poczta-polska' => ['api_key' => '', 'language' => '', 'endpoint' => ''],
    'india-post' => ['endpoint' => ''],
    'pos-malaysia' => ['user_key' => '', 'culture' => '', 'endpoint' => ''],
    'emirates-post' => ['account_no' => '', 'password' => '', 'endpoint' => ''],
    'magyar-posta' => ['access_token' => '', 'endpoint' => ''],
    'ceska-posta' => ['endpoint' => ''],
    'elta' => ['username' => '', 'password' => '', 'endpoint' => ''],
    'viettel-post' => ['endpoint' => ''],
    'zto-intl' => ['company_id' => '', 'secret' => '', 'endpoint' => ''],
    'yto-intl' => ['app_key' => '', 'app_secret' => '', 'endpoint' => ''],
    'jt-intl' => ['api_key' => '', 'secret' => '', 'endpoint' => ''],
    'winit' => ['app_key' => '', 'app_secret' => '', 'client_id' => '', 'client_secret' => '', 'platform' => '', 'language' => '', 'endpoint' => ''],
    'ukrposhta' => ['api_key' => '', 'lang' => '', 'endpoint' => ''],
    'turkey-post' => ['endpoint' => ''],
    'israel-post' => ['endpoint' => ''],
    'egypt-post' => ['endpoint' => ''],
    'saudi-post' => ['key' => '', 'endpoint' => ''],
    'south-african-post' => ['endpoint' => ''],
    'correos-mexico' => ['endpoint' => ''],
    'correo-argentino' => ['agreement' => '', 'key' => '', 'endpoint' => ''],
    'correos-chile' => ['token' => '', 'endpoint' => ''],
    'pos-indonesia' => ['endpoint' => ''],
    'phl-post' => ['endpoint' => ''],
    'pakistan-post' => ['endpoint' => ''],
    'kazpost' => ['endpoint' => ''],
    'romania-post' => ['endpoint' => ''],
    'croatia-post' => ['endpoint' => ''],
    'slovak-post' => ['endpoint' => '', 'locale' => ''],
    'slovenia-post' => ['key' => '', 'endpoint' => ''],
    'serbia-post' => ['key' => '', 'endpoint' => ''],
    'bulgaria-post' => ['key' => '', 'session_guid' => '', 'endpoint' => ''],
    'lithuania-post' => ['key' => '', 'endpoint' => ''],
    'latvia-post' => ['key' => '', 'endpoint' => ''],
    'iceland-post' => ['key' => '', 'endpoint' => ''],
    'malta-post' => ['key' => '', 'endpoint' => ''],
    'luxembourg-post' => ['key' => '', 'endpoint' => ''],
    'cyprus-post' => ['endpoint' => ''],
    'moldova-post' => ['endpoint' => ''],
    'albania-post' => ['endpoint' => ''],
    'belarus-post' => ['endpoint' => ''],
    'macedonia-post' => ['endpoint' => ''],
    'bosnia-post' => ['endpoint' => ''],
    'deutsche-post' => ['api_key' => '', 'endpoint' => ''],
    'montenegro-post' => ['endpoint' => ''],
    'andorra-post' => ['endpoint' => ''],
    'monaco-post' => ['endpoint' => ''],
    'liechtenstein-post' => ['endpoint' => ''],
    'san-marino-post' => ['endpoint' => ''],
    'vatican-post' => ['endpoint' => ''],
    'gibraltar-post' => ['endpoint' => ''],
    'jersey-post' => ['endpoint' => ''],
    'guernsey-post' => ['endpoint' => ''],
    'isle-of-man-post' => ['endpoint' => ''],
    'faroe-post' => ['endpoint' => ''],
    'greenland-post' => ['endpoint' => ''],
    'aland-post' => ['endpoint' => ''],
    'colombia-post' => ['endpoint' => ''],
    'peru-post' => ['endpoint' => ''],
    'uruguay-post' => ['endpoint' => ''],
    'paraguay-post' => ['endpoint' => ''],
    'bolivia-post' => ['endpoint' => ''],
    'ecuador-post' => ['endpoint' => ''],
    'venezuela-post' => ['endpoint' => ''],
    'costa-rica-post' => ['endpoint' => ''],
    'panama-post' => ['endpoint' => ''],
    'dominican-post' => ['endpoint' => ''],
    'guatemala-post' => ['endpoint' => ''],
    'honduras-post' => ['endpoint' => ''],
    'el-salvador-post' => ['endpoint' => ''],
    'nicaragua-post' => ['endpoint' => ''],
    'cuba-post' => ['endpoint' => ''],
    'jamaica-post' => ['endpoint' => ''],
    'trinidad-post' => ['endpoint' => ''],
    'barbados-post' => ['endpoint' => ''],
    'bahamas-post' => ['endpoint' => ''],
    'suriname-post' => ['endpoint' => ''],
    'guyana-post' => ['endpoint' => ''],
    'morocco-post' => ['key' => '', 'endpoint' => ''],
    'algeria-post' => ['key' => '', 'endpoint' => ''],
    'tunisia-post' => ['key' => '', 'endpoint' => ''],
    'kenya-post' => ['key' => '', 'endpoint' => ''],
    'nigeria-post' => ['key' => '', 'endpoint' => ''],
    'ethiopia-post' => ['key' => '', 'endpoint' => ''],
    'ghana-post' => ['key' => '', 'endpoint' => ''],
    'tanzania-post' => ['key' => '', 'endpoint' => ''],
    'uganda-post' => ['key' => '', 'endpoint' => ''],
    'rwanda-post' => ['key' => '', 'endpoint' => ''],
    'zambia-post' => ['key' => '', 'endpoint' => ''],
    'zimbabwe-post' => ['key' => '', 'endpoint' => ''],
    'mozambique-post' => ['key' => '', 'endpoint' => ''],
    'angola-post' => ['key' => '', 'endpoint' => ''],
    'senegal-post' => ['key' => '', 'endpoint' => ''],
    'ivory-coast-post' => ['key' => '', 'endpoint' => ''],
    'cameroon-post' => ['key' => '', 'endpoint' => ''],
    'mauritius-post' => ['key' => '', 'endpoint' => ''],
    'qatar-post' => ['key' => '', 'endpoint' => ''],
    'kuwait-post' => ['key' => '', 'endpoint' => ''],
    'bahrain-post' => ['key' => '', 'endpoint' => ''],
    'bangladesh-post' => ['endpoint' => ''],
    'nepal-post' => ['endpoint' => ''],
    'sri-lanka-post' => ['endpoint' => ''],
    'myanmar-post' => ['endpoint' => ''],
    'cambodia-post' => ['endpoint' => ''],
    'laos-post' => ['endpoint' => ''],
    'mongolia-post' => ['endpoint' => ''],
    'georgia-post' => ['endpoint' => ''],
    'azerbaijan-post' => ['endpoint' => ''],
    'armenia-post' => ['endpoint' => ''],
    'uzbekistan-post' => ['endpoint' => ''],
    'kyrgyzstan-post' => ['endpoint' => ''],
    'tajikistan-post' => ['endpoint' => ''],
    'turkmenistan-post' => ['endpoint' => ''],
    'afghanistan-post' => ['endpoint' => ''],
    'bhutan-post' => ['endpoint' => ''],
    'maldives-post' => ['endpoint' => ''],
    'brunei-post' => ['endpoint' => ''],
    'papua-post' => ['endpoint' => ''],
    'fiji-post' => ['endpoint' => ''],
    'samoa-post' => ['endpoint' => ''],
];
