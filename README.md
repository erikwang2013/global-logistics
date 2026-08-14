# global-logistics

统一门面的国内快递 / 国际物流轨迹查询 composer 包（PHP 8.1+，PSR-4，不绑定框架）。

## 快速开始

```bash
composer require erikwang2013/global-logistics
```

```php
<?php

use GlobalLogistics\Logistics;

Logistics::configure([
    'sf' => ['partner_id' => '...', 'checkword' => '...'],
    'zto' => ['company_id' => '...', 'secret' => '...'],
    'yto' => ['app_key' => '...', 'app_secret' => '...'],
    'jt' => ['api_key' => '...', 'secret' => '...'],
]);

// 自动识别通道（国内/国际）与承运商
$tracking = Logistics::track('SF1234567890');

// 显式指定
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
// 国际承运商接入后可用（国际批次规划中）
// $tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');

echo $tracking->status->name;      // DELIVERED
echo $tracking->latestDescription; // 快件已签收
```

## 已接入承运商

- 国内：顺丰(sf)、中通(zto)、圆通(yto)、极兔(jt) —— 轨迹查询可用；下单/面单/订阅按承运商开放情况接入中
- 国际：规划中（DHL/FedEx/UPS/TNT/USPS、专线小包、聚合平台、全球承运商、电商平台物流）

## 开发

```bash
composer install
composer test
```

无需真实密钥即可跑全量测试（适配器测试走 mock HTTP + fixture）。
