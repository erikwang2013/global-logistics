<?php

declare(strict_types=1);

// global-logistics 统一配置模板（Laravel / ThinkPHP / Hyperf / Webman 共享）。
// 顶层键 = 承运商代码，结构同 Logistics::configure() 入参。
// 密钥请填写到各框架自己的配置文件中（如 config/logistics.php、params['logistics']），切勿硬编码。

return [
    // 可选：自定义 PSR-18 HTTP 客户端实例（null 则自动构建 Guzzle）
    'http_client' => null,

    // 可选：单次请求失败后的重试次数（默认 2）
    'max_retries' => 2,

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
    'ane' => ['app_key' => ''],
    'cainiao' => ['logistic_provider_id' => '', 'secret_key' => ''],
    'china-post' => ['app_id' => '', 'app_secret' => ''],
    'suning' => ['app_key' => '', 'app_secret' => '', 'version_no' => ''],
    'uc' => ['partner_id' => '', 'token' => ''],
    'ymd' => ['partner_id' => '', 'token' => ''],
    'zjs' => ['partner_id' => '', 'token' => ''],

    // 国际（OAuth2、签名认证或无认证公开 API）
    'dhl' => ['client_id' => '', 'client_secret' => ''],
    'fedex' => ['client_id' => '', 'client_secret' => ''],
    'ups' => ['client_id' => '', 'client_secret' => ''],
    'usps' => ['user_id' => ''],
    'royal-mail' => ['client_id' => '', 'client_secret' => ''],
    'canada-post' => ['customer_number' => '', 'api_key' => ''],
    'australia-post' => ['api_key' => ''],
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
    'swiss-post' => ['client_id' => '', 'client_secret' => '', 'scope' => '', 'language' => ''],
    'yodel' => ['client_id' => '', 'client_secret' => '', 'base_url' => '', 'token_url' => ''],
    'yunexpress' => ['app_id' => '', 'app_secret' => '', 'source_key' => ''],
];
