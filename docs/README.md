# program-database 文档入口

这里是 `superpms/program-database` 面向开发者的详细文档。先从问题类型选择入口, 再进入对应模块。

## 先读

- 新接入项目或首次使用: [guide/quick-start.md](guide/quick-start.md)
- 调整数据库连接配置: [guide/configuration.md](guide/configuration.md)
- 写查询、模型、事务代码: [guide/query-and-transaction.md](guide/query-and-transaction.md)
- 使用注册表保存配置: [reference/registry-api.md](reference/registry-api.md)
- 核对 composer、bin、config、src 清单: [reference/source-inventory.md](reference/source-inventory.md)

## 按问题读

- composer、bin、config、src 清单: [reference/source-inventory.md](reference/source-inventory.md)
- Composer 安装后为什么有 `config/database.php`: [internals/runtime-contracts.md](internals/runtime-contracts.md)
- 启动时数据库配置如何进入 Think ORM: [internals/bootstrap-and-lifecycle.md](internals/bootstrap-and-lifecycle.md)
- Swoole 下为什么会自动切到连接池: [internals/bootstrap-and-lifecycle.md](internals/bootstrap-and-lifecycle.md)
- 池连接什么时候归还: [internals/mysql-pool.md](internals/mysql-pool.md)
- 注册表有哪些 API、返回值和异常行为: [reference/registry-api.md](reference/registry-api.md)
- 注册表怎样做平台/租户隔离: [reference/registry-api.md](reference/registry-api.md)
- 包级目录、autoload、配置投影、Facade 约定: [internals/runtime-contracts.md](internals/runtime-contracts.md)

## 分层说明

- `guide/`: 面向日常开发的使用方式和示例。
- `reference/`: 公开 API、配置项、参数、返回值和限制。
- `internals/`: 自动挂载、生命周期、连接池和 PMS 包协议。

## 不在这里读

- 业务端接口、菜单、权限和具体业务模型不属于本包文档。
- Think ORM 的完整查询语法不在这里复刻, 本文档只说明本包怎样把 Think ORM 接入 PMS。
- 旧 memory 文档只能作为线索, 当前文档以本包源码和 server 端实际引用为准。
