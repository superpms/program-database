# 快速开始

## 安装

```bash
composer require superpms/program-database
```

包安装后, PMS 的 vendor 挂载流程会读取 `composer.json` 的 `extra.pms.config`, 将 `resource/config.php` 投影为项目侧 `config/database.php`。如果目标文件已经存在, 当前投影逻辑不会覆盖它。

## 配置数据库

项目侧 `config/database.php` 至少需要设置默认连接和连接信息:

```php
return [
    'default' => 'connection1',
    'connections' => [
        'connection1' => [
            'type' => 'mysql',
            'hostname' => '127.0.0.1',
            'port' => 3306,
            'database' => 'app',
            'username' => 'app',
            'password' => 'secret',
            'charset' => 'utf8mb4',
            'prefix' => '',
            'debug' => true,
            'pool_count' => 64,
            'pool_wait_idle_time' => 28800,
        ],
    ],
];
```

启动时 `bin/autorun.php` 会在 `LIFECYCLE_BOOT` 读取 `config('database')`, 然后执行:

```php
\think\facade\Db::setConfig($dbConfig);
```

## 查询

本包不包裹 Think ORM 查询 API。配置接入完成后, 业务代码直接使用 `think\facade\Db` 或 Think `Model`:

```php
use think\facade\Db;

$rows = Db::name('system_config')
    ->where('parent', 'ROOT')
    ->select()
    ->toArray();
```

## 事务

```php
use think\facade\Db;

Db::startTrans();
try {
    Db::name('system_config')->where('key', 'APP_NAME')->update([
        'value' => 'Super PMS',
    ]);

    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
```

`pms\DbRegistry` 内部的批量保存、父节点更新和恢复方法也使用 `Db::startTrans()`, `Db::commit()`, `Db::rollback()`。调用方捕获异常时应避免吞掉失败原因。

## 注册表最小用法

注册表类通过继承 `pms\DbRegistry` 并指定 Think Model 使用:

```php
use pms\DbRegistry;

class AppRegistry extends DbRegistry
{
    protected string $modelClass = AppConfig::class;
}

$registry = AppRegistry::inst();

$registry->saveAllRaw([
    [
        'key' => 'APP_NAME',
        'value' => 'Super PMS',
        'name' => '应用名称',
        'description' => '前台展示名称',
        'parent' => 'ROOT',
    ],
]);

$name = $registry->get('APP_NAME');
```

推荐新增注册表项时使用 `saveAllRaw()` 或 `save()`, 并显式传入 `name` 与 `parent`。当前 `set()` 方法只插入 `parent`, `name`, `key`, `type`, 不写入 `value`, 更适合作为底层方法理解, 不宜作为首选新增入口。

## Swoole HTTP 下的差异

当 `in_swoole()` 为真且存在 `pms\hook\HttpLifecycleHook` 时, 包会在 HTTP boot 阶段把 `type=mysql` 的连接切换为 `pms\program\database\connector\MysqlPool`。请求 sandbox 销毁时会调用 `pdb_pool_autoclose()` 归还已借出的连接。
