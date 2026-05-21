# 运行协议与扩展点

## 包目录协议

本包遵循 PMS Composer 包的常见结构:

- `composer.json`: 包元信息、依赖、自动加载、安装投影。
- `bin/`: Composer files 入口、helper、autorun。
- `resource/`: 默认配置资源。
- `src/pms/`: PSR-4 源码, 命名空间为 `pms\`。
- `docs/`: 包级开发文档。

阅读或修改时, 先看 `composer.json` 和 `bin/`, 再看 `resource/`, 最后进入 `src/pms/`。

## 依赖协议

运行依赖:

- `php >= 8.1`
- `ext-pdo`
- `topthink/think-orm ^4.0`

开发依赖:

- `swoole/ide-helper ^6.0.0`
- `superpms/interpreter-http ^1.0.0`

`MysqlPool` 运行时依赖 Swoole 的 `ConnectionPool`。虽然 Swoole 只在 require-dev 中出现, 自动启用池化前会先通过 `in_swoole()` 判断当前运行环境。

## Autoload files 协议

`autoload.files` 会立即执行 `bin/autoload.php`。这个入口应保持轻量, 只装载常量、helper 和 autorun。真正的生命周期挂载放在 `bin/autorun.php`。

新增启动副作用时, 应优先接入生命周期 hook, 不要把复杂逻辑堆进 `autoload.php`。

## 配置投影协议

`extra.pms.config` 是 PMS 自己的安装投影协议, 不是 Composer 官方语义。它由 PMS vendor 安装挂载流程消费, 将包内资源复制到项目 `config/<key>.php`。

本包声明:

```json
"config": {
  "database": "resource/config.php"
}
```

运行时代码也读取 `config('database')`, 两者必须保持一致。

当前投影逻辑保护已有文件, 不覆盖项目侧配置。因此包升级后默认配置新增字段时, 不能假设项目配置会自动同步。

## Facade 约定

本包本身没有提供 `src/pms/facade/*` 门面类。server 端实际用法是在业务仓库中定义:

- `core\system\registry\PlatCfgRegistry extends pms\DbRegistry`
- `core\system\registry\TenantCfgRegistry extends pms\DbRegistry`
- `core\system\registry\PlatCfgRegistryFace extends pms\Facade`

PMS `Facade` 基类会缓存真实类实例, 缓存 key 是真实类名。`DbRegistry::inst()` 不走这个缓存, 它是 `new static(...$args)`。

如果要给注册表增加 facade, 推荐让 facade 类只覆盖 `getFacadeClass()`, 不把业务逻辑写进 facade。

## 注册表扩展点

`DbRegistry` 主要通过以下 protected 属性扩展:

- `$modelClass`: 必填, 指向 Think Model 类。
- `$defaultCreateParentKey`: 新建配置的默认父 key, 默认 `ROOT`。
- `$andWhere`: 所有主要查询追加的条件, 常用于租户隔离。
- `$restoreAttachDatum`: `restore()` 或 `saveAllRaw()` 创建数据时附加的字段, 常用于恢复租户配置时补 `tenant_uuid`。

server 端租户注册表就是通过 `$andWhere` 和 `$restoreAttachDatum` 让同一套注册表 API 带上租户边界。

## 连接回收协议

Swoole HTTP 请求级回收挂在 `LIFECYCLE_SANDBOX_DESTRUCT`:

- 数据库: `pdb_pool_autoclose()`
- Redis 包有自己的 `prdb_pool_autoclose()`

这条链只覆盖 HTTP sandbox。其他运行模型需要按自己的生命周期清理连接。

## 异常与返回结构

本包不定义 HTTP 响应结构。查询异常、事务异常和注册表异常按 Think ORM 与 PHP 异常传播。

`DbRegistry` 的方法返回值主要是:

- 读取: `mixed`, `array`, 不存在时常见为 `null`。
- 写入/删除/备份/恢复: `bool`。
- 存在性计数: `int`。
- 解包备份: `string|false`。

调用方不要把这些返回值包装成业务响应写在本包文档里; 业务响应属于 server 端应用层。

## 扩展建议

- 新增数据库类型池化时, 应在 `bin/autorun.php` 的 Swoole HTTP boot 改写处增加明确分支, 并提供对应 connector。
- 新增注册表元数据字段时, 同步检查 `saveAllRaw()`, `restore()`, 模型表结构和 server 端 kit 安装流程。
- 调整备份格式时, 保留 `registry` 版本字段并补恢复兼容策略。
- 修改连接回收时机前, 先确认目标运行模型是普通 HTTP、Swoole HTTP 还是常驻服务。
