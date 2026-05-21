# 配置

## 配置来源

本包的默认配置位于 [../../resource/config.php](../../resource/config.php)。`composer.json` 声明:

```json
{
  "extra": {
    "pms": {
      "config": {
        "database": "resource/config.php"
      }
    }
  }
}
```

PMS vendor 挂载流程会把它投影到项目侧 `config/database.php`。投影只负责默认文件落盘, 运行时仍以项目侧配置为准。

## 顶层配置项

| 配置项 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `default` | `string` | `connection1` | 默认连接名。 |
| `auto_timestamp` | `bool|string` | `false` | Think ORM 自动时间戳字段配置。字符串可指定 `int`, `timestamp`, `datetime`, `date`。 |
| `datetime_format` | `string` | `Y-m-d H:i:s` | 时间字段取出后的默认格式。 |
| `datetime_field` | `string` | 空字符串 | 时间字段名配置, 格式为 `create_time,update_time`。 |
| `connections` | `array` | `connection1` | 连接配置集合。 |

## 连接配置项

| 配置项 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `type` | `string|class-string` | `mysql` | 连接器类型。Swoole HTTP 下 `mysql` 会被改写为 `MysqlPool::class`。 |
| `deploy` | `int` | `0` | 数据库部署方式, `0` 为集中式, `1` 为分布式。 |
| `rw_separate` | `bool` | `false` | 主从部署时是否读写分离。 |
| `hostname` | `string` | `127.0.0.1` | 数据库主机。 |
| `port` | `int` | `3306` | 数据库端口。 |
| `database` | `string` | `you_database` | 数据库名。 |
| `username` | `string` | `you_username` | 用户名。 |
| `password` | `string` | `you_password` | 密码。 |
| `charset` | `string` | `utf8mb4` | 字符集。 |
| `prefix` | `string` | 空字符串 | 表前缀。 |
| `debug` | `bool` | `true` | 数据库调试模式。 |
| `pool_count` | `int` | `64` | Swoole HTTP 连接池最大连接数。 |
| `pool_wait_idle_time` | `int` | `28800` | Swoole HTTP 连接最大空闲时间, `MysqlPool` 实际会提前约 10 秒判定过期。 |

## 启动注入

普通启动链中, `bin/autorun.php` 在 `pms\hook\LifecycleHook` 存在时挂载 `LIFECYCLE_BOOT`:

```php
$dbConfig = config('database');
if ($dbConfig !== null) {
    \think\facade\Db::setConfig($dbConfig);
}
```

因此 `config('database') === null` 时不会注入配置。排查数据库未连接时, 先确认项目侧配置文件存在且能被项目 Config 驱动读取。

## Swoole HTTP 改写

Swoole HTTP 下会再次读取 `config('database')`, 遍历 `connections`, 将 `type=mysql` 的连接改写为:

```php
$dbConfig['connections'][$key]['type'] = \pms\program\database\connector\MysqlPool::class;
$dbConfig['connections'][$key]['builder'] = \think\db\builder\Mysql::class;
```

只有 `type` 为 `mysql` 的连接会被这段代码处理。其他连接类型不会自动池化。

## 配置限制

- 默认配置里的账号密码是占位值, 不能直接用于生产。
- `pool_count` 不应超过 MySQL `max_connections` 能承受的连接数。
- `pool_wait_idle_time` 不应大于 MySQL `wait_timeout`; 当前连接池会用该值减 10 秒设置连接过期时间。
- 安装投影不会覆盖已有 `config/database.php`; 配置不更新时应先检查目标文件是否已存在。
