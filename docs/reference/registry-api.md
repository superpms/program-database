# DbRegistry API

`pms\DbRegistry` 是一个抽象基类, 用数据库表保存树状 key/value 配置。它不是普通仓储类, 还包含类型转换、批量保存、备份、恢复、格式化等能力。

## 子类要求

最小子类需要指定 `$modelClass`:

```php
use pms\DbRegistry;

class PlatRegistry extends DbRegistry
{
    protected string $modelClass = SystemConfig::class;
}
```

模型表至少需要支持以下字段:

- `parent`
- `name`
- `key`
- `type`
- `value`
- `description`

如果子类通过 `$andWhere` 增加隔离条件, 恢复数据时还应通过 `$restoreAttachDatum` 补齐对应字段。

```php
class TenantRegistry extends DbRegistry
{
    protected string $modelClass = TenantConfig::class;

    public function __construct(string $tenantUUID)
    {
        $this->andWhere = [
            ['tenant_uuid', '=', $tenantUUID],
        ];

        $this->restoreAttachDatum = [
            'tenant_uuid' => $tenantUUID,
        ];
    }
}
```

## 静态构造

### `inst(...$args): static`

返回 `new static(...$args)`。它不走 PMS `Facade` 缓存, 每次调用都是新对象。

```php
$registry = TenantRegistry::inst($tenantUUID);
```

## 读取

### `get(string $key): mixed`

读取单个 key。不存在时返回 `null`。

### `gets(array $keys): array`

批量读取。数组成员支持三种形式:

```php
$data = $registry->gets([
    'APP_NAME',
    'MAP_TENCENT_KEY_CLIENT' => 'mapKey',
    'SHOP_GOODS_CREATE_REVIEW' => $review,
]);
```

- 数字索引值表示按原 key 返回。
- 字符串 key 加字符串值表示返回时改名为别名。
- 字符串 key 加非字符串值会把读取值写回 `$keys[$key]`, 同时返回原 key。

每个未命中的 key 会在返回数组中出现, 值为 `null`。

### `getP(string $parent): array`

读取指定父节点的直接子配置, 返回形如:

```php
[
    'CHILD_KEY' => $convertedValue,
]
```

只读取直接子级, 不递归读取所有后代。

## 写入

### `set(string $key, mixed $value, string $name, ?string $parentKey = null): bool`

插入一条新配置。当前实现写入 `parent`, `name`, `key`, `type`, 不写入 `value`; 插入失败或已存在时捕获异常并返回 `false`。

新增配置时优先考虑 `save()` 或 `saveAllRaw()`。

### `update(string $key, mixed $value): bool`

更新已有 key 的 `value` 和 `type`。查询条件会叠加 `$andWhere`。

### `updateP(string $parentNode, array $data): bool`

更新某个父节点下已有子配置。先要求父节点本身存在, 否则返回 `false`。只更新数据库中已经存在且出现在 `$data` 里的子节点。内部使用事务。

### `save(string $key, mixed $value, ?string $name = null, ?string $parentKey = null): bool`

如果 key 已存在则调用 `update()`, 否则调用 `set()`。因为 `set()` 当前不写入 `value`, 新建场景更建议使用 `saveAllRaw()`。

### `saveAll(array $array, bool $emptyCreate = true): bool`

接收 `[key => value]` 形式, 转换后调用 `saveAllRaw()`。适合批量更新或创建不需要 `name`, `description`, `parent` 元数据的配置。

### `saveAllRaw(array $data, bool $emptyCreate = true): bool`

接收结构化数组:

```php
$registry->saveAllRaw([
    [
        'key' => 'APP_NAME',
        'value' => 'Super PMS',
        'name' => '应用名称',
        'description' => '前台展示名称',
        'parent' => 'ROOT',
    ],
]);
```

行为:

- 内部开启事务。
- 已存在 key 时保存新值和可选元数据。
- 不存在 key 且 `$emptyCreate=true` 时创建。
- 不存在 key 且 `$emptyCreate=false` 时跳过。
- 保存时会把 `key` 转为大写。
- 新建或显式提供 `parent` 时会写入父节点, 缺省父节点来自 `$defaultCreateParentKey`, 默认 `ROOT`。
- 创建时会合并 `$restoreAttachDatum`。

注意: 当前代码在判断 `parent` 时直接访问 `$item['parent']`, 调用方应为需要创建的项显式提供 `parent`, 避免未定义数组键风险。

### `saveP(string $parentKey, array $data): bool`

按父节点批量保存。当前实现开头是:

```php
if ($this->has($parentKey)) {
    return false;
}
```

也就是说父节点存在时会直接返回 `false`。使用前必须按当前源码行为评估是否符合调用场景。

## 存在性

### `has(string $key): bool`

判断单个 key 是否存在。

### `have(array $keys): bool`

判断多个 key 是否全部存在。

### `haveCount(array $keys): int`

返回存在的 key 数量。

## 删除

### `delete(string|array $key): bool`

删除一个或多个 key, 条件会叠加 `$andWhere`。

### `deleteP(string $key): bool`

删除指定 key 及其所有后代。方法会读取全表数据, 用 `parent` 关系递归找出后代 key, 再批量删除。

## 备份与恢复

### `backup(string $backPath, ?string $password = null): bool`

将当前注册表数据写入文件。目录不存在时会尝试创建。提供密码时, 写入 AES-256-CBC 加密后的字符串。

### `generateBackupStr(?string $password = null): string`

生成备份字符串。明文结构为:

```json
{
  "registry": "1.0.0",
  "data": []
}
```

### `restore(string $filePath, ?string $password = null): bool`

从文件恢复注册表。恢复流程会:

- 读取文件内容。
- 按密码解密或直接使用明文。
- JSON 解码。
- 过滤包含 `key` 与 `parent` 的数据项。
- 删除当前注册表中备份包含的 key。
- 重新创建备份中的数据。
- 创建时合并 `$restoreAttachDatum`。

内部使用事务。读取失败、解密失败或 JSON 解析失败时返回 `false`。

### `unpackBackupStr(string $data, ?string $password = null): string|false`

解包备份字符串。无密码时原样返回, 有密码时尝试解密。

## 格式化

### `format(): bool`

执行 `truncate <table>` 清空注册表表。该操作不叠加 `$andWhere`, 对共享表或租户表使用时必须非常谨慎。

## 类型转换

写入时:

- `array` -> JSON 字符串, 类型 `JSONARRAY`
- `object` -> `toArray()` 或 JSON 字符串
- `boolean` -> `0` / `1`
- 其他值原样保存

读取时:

- `BOOL` / `BOOLEAN` -> bool
- `JSONARRAY` -> array
- `INTEGER` -> int
- `DOUBLE` -> float
- `STRING` -> string
- `NULL` -> null

## 异常与限制

- 多个批量方法内部捕获异常后会回滚并重新抛出。
- `set()` 捕获所有异常并返回 `false`。
- `format()` 是整表 truncate, 不适合带租户隔离的共享表日常调用。
- `deleteP()` 需要先把表数据取出到内存中, 大表使用要评估成本。
- 加密备份依赖 OpenSSL 和 AES-256-CBC; 密码错误时 `restore()` 会返回 `false`。
