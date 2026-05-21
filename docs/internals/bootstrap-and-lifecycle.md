# 自动加载与生命周期

## Composer 入口

`composer.json` 声明:

```json
{
  "autoload": {
    "files": [
      "bin/autoload.php"
    ],
    "psr-4": {
      "pms\\": "src/pms/"
    }
  }
}
```

因此项目 Composer autoload 被加载时, `bin/autoload.php` 会作为文件入口执行。

## `bin/autoload.php`

当前入口只做编排:

```php
require_once __DIR__.'/const.php';
require_once __DIR__.'/helper.php';
require_once __DIR__.'/autorun.php';
```

- `const.php` 当前没有定义常量。
- `helper.php` 定义 `in_swoole()` 和 `pdb_pool_autoclose()`。
- `autorun.php` 负责把数据库接进 PMS 生命周期。

## 普通生命周期

当 `pms\hook\LifecycleHook` 存在时, 包挂载 `LIFECYCLE_BOOT`:

```php
\pms\hook\LifecycleHook::mount(LIFECYCLE_BOOT, function () {
    $dbConfig = config('database');
    if ($dbConfig !== null) {
        \think\facade\Db::setConfig($dbConfig);
    }
});
```

这条链只负责把项目配置注入 Think ORM。普通 PHP-FPM、普通 HTTP 或 CLI 模式不会自动启用 `MysqlPool`。

## Swoole HTTP 生命周期

`in_swoole()` 的判定是:

```php
defined('SWOOLE_VERSION') && SWOOLE_VERSION !== null
```

当它为真且 `pms\hook\HttpLifecycleHook` 存在时, 包会挂载两段 HTTP 生命周期。

### HTTP boot

在 `LIFECYCLE_BOOT` 中读取 `config('database')`, 遍历 `connections`, 对 `type=mysql` 的连接做改写:

```php
$dbConfig['connections'][$key]['type'] = \pms\program\database\connector\MysqlPool::class;
$dbConfig['connections'][$key]['builder'] = \think\db\builder\Mysql::class;
```

随后再次调用 `Db::setConfig($dbConfig)`。

### Sandbox destruct

在 `LIFECYCLE_SANDBOX_DESTRUCT` 中执行:

```php
try {
    pdb_pool_autoclose();
} catch (\Throwable $e) {
    echo "数据库连接池错误：" . $e->getMessage() . "\r\n";
}
```

这一步负责在请求级 sandbox 结束时归还当前请求借出的连接。

## `pdb_pool_autoclose()`

helper 实现:

```php
function pdb_pool_autoclose(): void
{
    $connector = \think\facade\Db::getInstance();
    if (!empty($connector)) {
        foreach ($connector as $value) {
            $value->close();
        }
    }
}
```

它假定 `Db::getInstance()` 可遍历且其中对象提供 `close()`。在当前 Swoole 池化链中, `MysqlPool::close()` 会把借出的 PDO 放回连接池。

## 运行流程

1. Composer 加载 `bin/autoload.php`。
2. 包加载 helper 和 autorun。
3. 普通 `LifecycleHook` boot 阶段把 `config('database')` 注入 Think ORM。
4. 如果处于 Swoole HTTP, HTTP boot 阶段把 MySQL 连接器切为 `MysqlPool`。
5. 业务代码使用 `think\facade\Db` 或模型查询。
6. Swoole HTTP sandbox 销毁时调用 `pdb_pool_autoclose()`。
7. `MysqlPool::close()` 把已借出的 PDO 归还到 `Swoole\ConnectionPool`。

## 排查顺序

- 类能加载但数据库无配置: 查 `config('database')` 是否为空。
- 安装后没有 `config/database.php`: 查 `composer.json` 的 `extra.pms.config` 和 vendor 安装挂载是否执行。
- Swoole 下没有池化: 查 `SWOOLE_VERSION`, `HttpLifecycleHook`, 以及连接 `type` 是否为 `mysql`。
- 连接不归还: 查请求是否走到 `LIFECYCLE_SANDBOX_DESTRUCT`, 以及 `Db::getInstance()` 里连接器是否实现 `close()`。
