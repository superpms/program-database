# MysqlPool

`pms\program\database\connector\MysqlPool` 继承 `think\db\connector\Mysql`, 在 Swoole HTTP 下替换 Think ORM 默认 MySQL connector。

## 何时启用

只有同时满足以下条件才会由本包自动启用:

- `in_swoole()` 为真。
- `pms\hook\HttpLifecycleHook` 类存在。
- 数据库连接配置中的 `type` 是 `mysql`。
- `bin/autorun.php` 已经通过 Composer autoload 执行。

启用时配置会被改写为:

```php
'type' => \pms\program\database\connector\MysqlPool::class,
'builder' => \think\db\builder\Mysql::class,
```

## 池创建

`createPdo($dsn, $username, $password, $params)` 以 DSN 为 key 懒创建 `Swoole\ConnectionPool`:

```php
$this->pool[$dsn] = new ConnectionPool(function () use ($dsn, $username, $password, $params) {
    return parent::createPdo($dsn, $username, $password, $params);
}, $this->config['pool_count'] ?? 64);
```

同一个 connector 实例中, 相同 DSN 复用同一个池。

## 借出连接

`getRealPdo($dsn)` 从池里 `get()` 一个 PDO:

```php
$pdo = $this->pool[$dsn]->get();
```

如果 PDO 上存在 `last_time` 且已经过期, 当前实现会将其置空并放回池, 然后递归再取一次。未过期时会刷新:

```php
$pdo->last_time = time() + (($this->config['pool_wait_idle_time'] ?? 28800) - 10);
$pdo->_dsn = $dsn;
```

`_dsn` 用于后续归还时找到对应连接池。

## 归还连接

`close()` 遍历 connector 的 `$links`, 按每个 PDO 的 `_dsn` 放回池:

```php
foreach ($this->links as $link) {
    $this->pool[$link->_dsn]->put($link);
}
parent::close();
```

在 PMS Swoole HTTP 链路中, `close()` 由 `pdb_pool_autoclose()` 在 `LIFECYCLE_SANDBOX_DESTRUCT` 阶段触发。

## 配置项

- `pool_count`: 每个 DSN 的连接池容量, 默认 `64`。
- `pool_wait_idle_time`: 连接最大空闲时间, 默认 `28800`; 实际刷新 `last_time` 时减 10 秒。

## 生命周期边界

`MysqlPool` 只解决 Swoole HTTP 请求内的 PDO 借还。它不改变业务代码写查询和事务的方式。

普通模式下, `type=mysql` 不会被本包自动替换为 `MysqlPool`。常驻服务、队列或自定义事件循环如果不走 HTTP sandbox, 也不能依赖 `LIFECYCLE_SANDBOX_DESTRUCT` 自动归还连接。

## 注意事项

- `pool_count` 应按 MySQL `max_connections`、worker 数和项目并发量一起估算。
- `pool_wait_idle_time` 应小于或等于 MySQL `wait_timeout`。
- 当前代码通过动态属性给 PDO 写入 `last_time` 和 `_dsn`; 如果运行环境限制动态属性, 需要重新验证兼容性。
- 连接过期分支会把 `null` 放回池后递归取连接, 这是当前源码行为; 调整前应先写运行级验证。
- `close()` 依赖 `$links` 中的 PDO 带 `_dsn`; 如果外部绕过 connector 管理 PDO, 归还链可能不完整。
