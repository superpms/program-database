# superpms/program-database

`superpms/program-database` 是 PMS Composer 体系里的数据库 program 包。它负责把项目的 `database` 配置接入 Think ORM, 在 Swoole HTTP 运行时把 MySQL 连接切换为连接池实现, 并提供 `pms\DbRegistry` 注册表抽象。

## 能力定位

- 安装期通过 `extra.pms.config` 投影默认数据库配置。
- 启动期读取 `config('database')` 并调用 `think\facade\Db::setConfig()`。
- 在 Swoole HTTP 下把 `type=mysql` 的连接改写为 `pms\program\database\connector\MysqlPool`。
- 在 sandbox 销毁阶段调用 `pdb_pool_autoclose()` 归还已借出的池连接。
- 提供 `pms\DbRegistry` 用于基于数据库表保存树状配置、类型转换、批量保存、备份和恢复。

这个包不定义业务端功能, 也不直接提供业务模型。业务代码仍然使用 Think ORM 的查询、模型和事务能力。

## 安装与挂载

```bash
composer require superpms/program-database
```

安装后需要确保项目执行了 PMS 的 vendor 安装挂载流程, 让 `composer.json` 中的配置投影生效:

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

投影结果是项目侧 `config/database.php`。当前安装投影逻辑只在目标配置不存在时复制默认文件, 不覆盖已有项目配置。

## 快速使用

普通查询和事务直接使用 Think ORM:

```php
use think\facade\Db;

$users = Db::name('user')->where('status', 1)->select();

Db::startTrans();
try {
    Db::name('user')->where('id', $id)->update(['status' => 1]);
    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
```

注册表能力通过继承 `pms\DbRegistry` 使用:

```php
use pms\DbRegistry;
use think\Model;

class AppRegistry extends DbRegistry
{
    protected string $modelClass = AppConfig::class;
}

$registry = AppRegistry::inst();
$registry->saveAllRaw([
    ['key' => 'APP_NAME', 'value' => 'Super PMS', 'name' => '应用名称', 'parent' => 'ROOT'],
]);

$name = $registry->get('APP_NAME');
```

如果注册表需要租户隔离, 子类可以设置 `$andWhere` 与 `$restoreAttachDatum`, 让查询和恢复都携带隔离字段。

## 主要模块

- `composer.json`: 声明 PHP/PDO/Think ORM 依赖、PSR-4 自动加载和 `extra.pms.config` 投影。
- `bin/autoload.php`: Composer `autoload.files` 入口, 依次载入常量、helper 和 autorun。
- `bin/helper.php`: 提供 `in_swoole()` 与 `pdb_pool_autoclose()`。
- `bin/autorun.php`: 挂载生命周期 hook, 注入数据库配置, Swoole 下切换连接池并注册回收逻辑。
- `resource/config.php`: 默认数据库配置模板。
- `src/pms/program/database/connector/MysqlPool.php`: Think ORM MySQL connector 的 Swoole 连接池版本。
- `src/pms/DbRegistry.php`: 数据库注册表基类。

## 文档导航

- [docs/README.md](docs/README.md): 文档入口和阅读路径。
- [docs/guide/quick-start.md](docs/guide/quick-start.md): 安装、配置、查询和注册表最小示例。
- [docs/guide/configuration.md](docs/guide/configuration.md): `database` 配置项和投影规则。
- [docs/guide/query-and-transaction.md](docs/guide/query-and-transaction.md): 查询、模型和事务使用方式。
- [docs/reference/registry-api.md](docs/reference/registry-api.md): `pms\DbRegistry` API 参考。
- [docs/internals/bootstrap-and-lifecycle.md](docs/internals/bootstrap-and-lifecycle.md): 自动加载、生命周期和 Swoole 池化切换。
- [docs/internals/mysql-pool.md](docs/internals/mysql-pool.md): `MysqlPool` 连接借还和限制。
- [docs/internals/runtime-contracts.md](docs/internals/runtime-contracts.md): 包目录、Facade、配置投影和连接回收约定。

## 注意事项

- `resource/config.php` 里的 `database`, `username`, `password` 是占位值, 项目必须改成真实连接信息。
- `MysqlPool` 只在 `in_swoole()` 为真且 `pms\hook\HttpLifecycleHook` 存在时自动启用。
- 连接回收依赖 `LIFECYCLE_SANDBOX_DESTRUCT`; 不走 HTTP sandbox 的常驻进程不能假设自动回收一定发生。
- `DbRegistry` 的模型表需要包含 `parent`, `name`, `key`, `type`, `value`, `description` 等注册表字段; 租户隔离类还需要自己的隔离字段。
- `DbRegistry` 部分方法会开启事务并在失败时回滚; 调用方仍需按业务边界处理异常。

## 版权

PmsPHP 遵循 Apache-2.0 协议发布。更多细节参阅 [LICENSE.txt](LICENSE.txt)。
