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

    // 国际（OAuth2 或 签名认证）
    'dhl' => ['client_id' => '', 'client_secret' => ''],
    'fedex' => ['client_id' => '', 'client_secret' => ''],
    'ups' => ['client_id' => '', 'client_secret' => ''],
    'usps' => ['user_id' => ''],
];
