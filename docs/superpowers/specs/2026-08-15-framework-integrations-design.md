# 框架集成设计（Laravel / ThinkPHP 8 / Hyperf / Webman / Yii 2）

**目标：** 单包 `erikwang2013/global-logistics` 保持 library 类型、不硬依赖任何框架，同时为 5 大 PHP 框架提供「按框架官方规则」的即装即用集成，密钥全部通过各框架配置注入。

**核心约束：**

1. 不得在 `require` 中依赖任何框架 —— 集成类只有在对应框架运行时才会被加载（PHP 惰性加载，未引用不加载）。
2. 各框架机制必须用官方通道（composer `extra` 键 / 插件协议），用户零手工注册。
3. 共享一份配置模板 `config/logistics.php`（顶层键 = 承运商代码，与 `Logistics::configure()` 入参一致），各框架以自己的机制暴露该配置。

---

## 1. 已核实的官方机制（2026-08-15 查证）

| 框架 | 机制 | 官方入口 |
|---|---|---|
| Laravel | `extra.laravel.providers` 包发现，安装后自动注册；`vendor:publish` 发布配置 | Package Discovery |
| ThinkPHP 8 | `extra.think.services` → composer 安装时自动生成 `vendor/services.php`（或 `php think service:discover`） | think-framework Service 机制 |
| Hyperf | `extra.hyperf.config` → 指向 ConfigProvider 类，`__invoke()` 返回 `publish` 数组；`php bin/hyperf.php vendor:publish` 发布配置 | Hyperf ConfigProvider 机制 |
| Webman | **文件级协议，无 extra 键**：`support\Plugin::install/update/uninstall`（根项目 composer.json 的 `post-package-install` 等钩子触发）遍历被安装包 PSR-4 命名空间，检测 `\{namespace}Install::WEBMAN_PLUGIN` 常量 → 调用 `Install::install(true)` | webman 基础插件协议 |
| Yii 2 | `type: "yii2-extension"` + `extra.bootstrap` → yiisoft/yii2-composer 写入 `vendor/yiisoft/extensions.php`，应用每次引导时实例化 bootstrap 类并调用 `bootstrap($app)` | yii2-extension 包类型 |

**Webman 关键实现细节**（walkor/webman-framework `support/Plugin.php` 源码核实）：

```php
$psr4 = $operation->getPackage()->getAutoload()['psr-4'] ?? [];  // 本包: GlobalLogistics\ => src/
foreach ($psr4 as $namespace => $path) {
    $pluginConst = "\\{$namespace}Install::WEBMAN_PLUGIN";   // \GlobalLogistics\Install::WEBMAN_PLUGIN
    if (!defined($pluginConst)) continue;                      // defined() 触发 PSR-4 autoload
    if (is_callable("\\{$namespace}Install::install")) { $installFunction(true); }
}
```

→ 本包只需在 `src/Install.php` 声明 `GlobalLogistics\Install`（const `WEBMAN_PLUGIN = true`，静态 `install/update/uninstall`）。update 存在则调用 `update()`，否则回退 `install(false)`。

---

## 2. 共享配置模板 `config/logistics.php`

新文件，返回 `Logistics::configure()` 完整入参形状（12 个承运商键 + `http_client`/`max_retries`），全部默认空/注释。各框架引用或发布此文件：

- Laravel：`mergeConfigFrom()` + publish 到 `config/logistics.php`
- ThinkPHP：`Config::merge()` 到 `logistics` 段
- Hyperf：publish 到 `config/autoload/logistics.php`
- Webman：Install 拷贝为 `config/plugin/erikwang2013/global-logistics/app.php`
- Yii 2：无需文件，用户写入 `params['logistics']`

## 3. 集成类设计（`src/Framework/`）

### 3.1 LaravelServiceProvider（extends `Illuminate\Support\ServiceProvider`）

- `register()`：`mergeConfigFrom(__DIR__.'/../../config/logistics.php', 'logistics')`
- `boot()`：`publishes([... => config_path('logistics.php')], 'global-logistics')`（仅 console）；`Logistics::configure((array) config('logistics'))`

### 3.2 ThinkService（extends `think\Service`）

- `register()`：`$this->app->config->merge(require __DIR__.'/../../config/logistics.php', 'logistics')`
- `boot()`：`Logistics::configure((array) $this->app->config->get('logistics', []))`
- 用户可覆盖：应用 `config/logistics.php` 返回同结构数组（merge 语义：应用配置优先）。

### 3.3 HyperfConfigProvider（普通类，`__invoke(): array`）

- 返回 `['publish' => [['id' => 'logistics', 'description' => 'global-logistics 配置', 'source' => __DIR__.'/../../config/logistics.php', 'destination' => BASE_PATH.'/config/autoload/logistics.php']]]`
- Hyperf 无服务提供者概念，用户经 `Hyperf\Utils\ApplicationContext` 无需绑定（库为静态门面）；文档指引在 `config/autoload/dependencies.php` 无需改动。

### 3.4 Install（`src/Install.php`，namespace `GlobalLogistics`，webman 协议）

- `const WEBMAN_PLUGIN = true`
- `install(bool $customize = false)`：将 `config/logistics.php` 拷贝为项目 `config/plugin/erikwang2013/global-logistics/app.php`（`base_path()` 优先，`getcwd()` 兜底——composer 钩子运行时 webman helpers 已加载，PHPUnit 下走 getcwd）
- `update()`：同 install（webman 对存在 update() 的插件调用它而非 install(false)）
- `uninstall()`：删除 `config/plugin/erikwang2013/global-logistics/`
- 用户读取：`config('plugin.erikwang2013.global-logistics.app.sf.partner_id')`

### 3.5 YiiBootstrap（implements `yii\base\BootstrapInterface`）

- `bootstrap($app)`：`Logistics::configure((array) ($app->params['logistics'] ?? []))`
- composer.json：`"type": "yii2-extension"` + `"extra": {"bootstrap": "GlobalLogistics\\Framework\\YiiBootstrap"}`

## 4. composer.json 变更

```json
"type": "yii2-extension",
"extra": {
    "laravel": { "providers": ["GlobalLogistics\\Framework\\LaravelServiceProvider"] },
    "think": { "services": ["GlobalLogistics\\Framework\\ThinkService"] },
    "hyperf": { "config": "GlobalLogistics\\Framework\\HyperfConfigProvider" },
    "bootstrap": "GlobalLogistics\\Framework\\YiiBootstrap"
},
"suggest": {
    "illuminate/support": "Laravel 服务提供者集成",
    "topthink/framework": "ThinkPHP 8 服务集成",
    "hyperf/framework": "Hyperf ConfigProvider 集成",
    "workerman/webman-framework": "Webman 插件集成（Install.php 自动拷贝配置）",
    "yiisoft/yii2": "Yii 2 引导集成"
},
"require-dev": {
    "+ illuminate/container": "^10.0|^11.0|^12.0",
    "+ illuminate/support": "^10.0|^11.0|^12.0",
    "+ illuminate/config": "^10.0|^11.0|^12.0",
    "+ topthink/framework": "^8.0",
    "+ yiisoft/yii2": "^2.0.49"
}
```

说明：

- `type: "yii2-extension"` 对非 Yii 项目仅为元数据，无副作用；Yii 项目由 yiisoft/yii2-composer（yii2 自带依赖）写入 extensions.php。
- Hyperf/Webman 无需任何 dev 依赖（HyperfConfigProvider 是普通类；Install.php 不引用 webman 类，`base_path()` 有兜底）。
- Yii 2 bootstrap 类必须实现 `BootstrapInterface`（Yii 源码 `instanceof` 检查），故 dev 依赖 yiisoft/yii2。
- 已知边界（yiisoft/yii2 #5047）：全新空 vendor 且无其他 Yii 依赖时，扩展可能先于 yii2-composer 安装而漏写 extensions.php；Yii 项目（yiisoft/yii2 必装）实际不会发生，文档注明兜底：`composer dump-autoload`。

## 5. 测试策略（TDD，真实框架类）

- `tests/Framework/LaravelServiceProviderTest.php`：真实 `Illuminate\Container\Container` + `Illuminate\Config\Repository`；断言 register 后 config 合并、boot 后 `Logistics::track` 可用（fake http）、publishes 路径正确。
- `tests/Framework/ThinkServiceTest.php`：真实 `think\App` + `think\Config`；断言 register/boot 后配置生效。
- `tests/Framework/HyperfConfigProviderTest.php`：零依赖，断言 `__invoke()` 返回 publish 数组结构（id/source/destination）。
- `tests/Framework/WebmanInstallTest.php`：临时目录 chdir 为项目根，调用 `Install::install()` 断言 `config/plugin/erikwang2013/global-logistics/app.php` 生成、`uninstall()` 删除、`update()` 保留语义。
- `tests/Framework/YiiBootstrapTest.php`：真实 `yii\console\Application`（memory db 不需要，纯 params 注入）；断言 bootstrap() 后 `Logistics::track` 可用。
- 全部沿用 `$this->assertSame` 约定；密钥走临时目录/内存配置，无硬编码。

## 6. 文档与 CI

- README 新增「框架集成」章节：每框架一段（安装 → 配置 → 使用），含 Webman `config('plugin.erikwang2013.global-logistics.app')`、Yii `params['logistics']` 示例。
- GitHub Actions 现有 PHP 8.2/8.3 matrix 不变（require-dev 由 composer install 自动带入）。

## 7. 实施顺序

① Laravel → ② ThinkPHP → ③ Hyperf → ④ Webman → ⑤ Yii → ⑥ composer.json 聚合 + README。每步 TDD（先测后码）、独立 commit、跑全量测试。
