# global-logistics

统一门面的国内快递 / 国际物流轨迹查询 composer 包（PHP 8.2+，PSR-4，不绑定框架）。

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
    'dhl' => ['client_id' => '...', 'client_secret' => '...'],
    'fedex' => ['client_id' => '...', 'client_secret' => '...'],
    'ups' => ['client_id' => '...', 'client_secret' => '...'],
    'usps' => ['user_id' => '...'],
]);

// 自动识别通道（国内/国际）与承运商
$tracking = Logistics::track('SF1234567890');

// 显式指定
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');

echo $tracking->status->name;      // DELIVERED
echo $tracking->latestDescription; // 快件已签收
```

## 已接入承运商

- 国内：顺丰(sf)、中通(zto)、圆通(yto)、极兔(jt)、韵达(yd)、申通(sto)、京东(jd)、EMS(ems) —— 轨迹查询可用；下单/面单/订阅按承运商开放情况接入中
- 国际：DHL(dhl)、FedEx(fedex)、UPS(ups)、USPS(usps) —— 轨迹查询可用（OAuth2 认证，config 需 client_id/client_secret，USPS 需 user_id）；下单/面单/订阅按承运商开放情况接入中

## 框架集成

`composer require erikwang2013/global-logistics` 后按框架自动发现，无需手工注册；配置模板统一为承运商代码为顶层键的数组（见 `config/logistics.php`，结构与 `Logistics::configure()` 入参一致）。

### Laravel

- 自动注册：composer `extra.laravel.providers` 包发现，无需配置
- 发布配置：`php artisan vendor:publish --tag=global-logistics`（生成 `config/logistics.php`）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### ThinkPHP 8

- 自动注册：composer `extra.think.services`（安装时生成 `vendor/services.php`，也可 `php think service:discover` 重新生成）
- 配置：应用 `config/logistics.php` 返回同结构数组（覆盖包内默认值）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Hyperf

- 自动发现：composer `extra.hyperf.config` 指向 ConfigProvider
- 发布配置：`php bin/hyperf.php vendor:publish`（发布到 `config/autoload/logistics.php`）
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Webman

- 自动安装：webman 项目模板自带 `post-package-install` 等 composer 钩子，安装/更新时自动把配置拷贝到 `config/plugin/erikwang2013/global-logistics/`，卸载时自动删除
- 读取配置：`config('plugin.erikwang2013.global-logistics.app.sf.partner_id')`
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`

### Yii 2

- 自动注册：包类型 `yii2-extension` + composer `extra.bootstrap`，应用每次引导时执行
- 配置：应用配置 `params` 中加 `'logistics' => [...同结构数组...]`
- 使用：`\GlobalLogistics\Logistics::track('SF1234567890')`
- 若 `vendor/yiisoft/extensions.php` 未出现本包条目（罕见），运行 `composer dump-autoload` 重建

## 开发

```bash
composer install
composer test
```

无需真实密钥即可跑全量测试（适配器测试走 mock HTTP + fixture；框架集成测试使用真实框架类）。
