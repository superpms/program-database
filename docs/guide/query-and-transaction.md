# 查询与事务

## 查询入口

本包接入 Think ORM, 但不重新封装查询 API。开发时直接使用:

- `think\facade\Db`
- 继承 `think\Model` 的业务模型
- Think ORM 的连接、查询构造器、模型关联和事务方法

```php
use think\facade\Db;

$item = Db::name('system_config')
    ->where('key', 'SYSTEM_INFORMATION_NAME')
    ->find();
```

## 模型使用

`DbRegistry` 要求子类指定 `$modelClass`, 该类应是 Think `Model`:

```php
use think\Model;

class AppConfig extends Model
{
}
```

注册表基类内部通过 `new $modelClass()` 和 `$model::where(...)` 操作数据表。因此模型的表名、连接和字段映射仍遵循 Think ORM 规则。

## 事务模式

业务侧事务仍使用 Think facade:

```php
use think\facade\Db;

Db::startTrans();
try {
    $ok = Db::name('order')->where('id', $id)->update(['status' => 2]);
    if ($ok === false) {
        Db::rollback();
        return false;
    }

    Db::commit();
    return true;
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
```

当前 server 端也按这个模式在安装、支付、商品、活动等流程中使用数据库事务。

## 注册表内置事务

`pms\DbRegistry` 中这些方法会自己开启事务:

- `updateP()`
- `saveAllRaw()`
- `saveP()`
- `restore()`

这些方法在内部异常时会 `rollback()` 并重新抛出异常。调用方如果外层也开启事务, 需要确认 Think ORM 当前连接的嵌套事务行为符合预期。

## 返回值约定

Think ORM 的 `save()`, `delete()`, `update()` 等方法可能返回 `false`, `0`, 影响行数或其他可转换为 bool 的值。当前本包代码多数以 `false` 判断失败:

```php
if ($status === false) {
    Db::rollback();
    return false;
}
```

开发时不要把 `0` 影响行误判为异常失败, 也不要只用宽松空值判断替代 Think ORM 的真实返回约定。

## Swoole 连接回收

在 Swoole HTTP 请求中, 业务代码通常不需要手动归还数据库连接。请求 sandbox 析构阶段会调用 `pdb_pool_autoclose()`, 它会遍历 `Db::getInstance()` 内的连接器并执行 `close()`。

不走 HTTP sandbox 的常驻进程或自定义事件循环不能依赖这条自动回收链, 需要在自己的生命周期里处理连接关闭。
