# 源码清单与包入口

本文记录 `superpms/program-database` 的包级入口、自动加载声明、配置投影和一方源码文件，作为功能覆盖核查的基准。

## composer.json

| 项 | 值 |
| --- | --- |
| `autoload.files` | `bin/autoload.php` |
| `autoload.psr-4` | `pms\\` -> `src/pms/` |
| `extra.pms.config` | `database` -> `resource/config.php` |
| `bin` | 无 |

## bin 文件

| 文件 | 作用 |
| --- | --- |
| `bin/autoload.php` | Composer files 入口，加载 `const.php`、`helper.php`、`autorun.php` |
| `bin/const.php` | 当前无常量定义 |
| `bin/helper.php` | 定义 `in_swoole()` 和 `pdb_pool_autoclose()` |
| `bin/autorun.php` | 普通 `LIFECYCLE_BOOT` 注入 Think ORM 配置；Swoole HTTP 下改写 MySQL 连接器并在 sandbox 销毁阶段归还连接 |

## resource/config.php

`resource/config.php` 通过 `extra.pms.config.database` 投影为项目侧 `database` 配置。顶层包含 `default`、`auto_timestamp`、`datetime_format`、`datetime_field`、`connections`；默认连接包含 MySQL 类型、主机、端口、库名、账号、密码、字符集、前缀、调试、`pool_count`、`pool_wait_idle_time` 等字段。

## src 一方源码

| 文件 | 公开功能面 |
| --- | --- |
| `src/pms/DbRegistry.php` | 抽象注册表基类；提供读取、批量读取、父级读取、写入、批量保存、存在性判断、删除、备份、恢复、格式化和 `inst()` 静态构造 |
| `src/pms/program/database/connector/MysqlPool.php` | Swoole `ConnectionPool` 版 MySQL connector；复用 Think ORM MySQL connector，借出 PDO 并在 `close()` 时归还 |

## 覆盖入口

- 接入、查询和事务见 [快速开始](../guide/quick-start.md)、[配置](../guide/configuration.md)、[查询与事务](../guide/query-and-transaction.md)。
- 注册表 API 见 [DbRegistry API](registry-api.md)。
- 自动加载、helper 和生命周期见 [自动加载与生命周期](../internals/bootstrap-and-lifecycle.md)。
- 连接池行为见 [MysqlPool](../internals/mysql-pool.md)。
- 包协议和扩展点见 [运行协议与扩展点](../internals/runtime-contracts.md)。
