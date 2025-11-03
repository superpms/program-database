<?php
if(class_exists('\pms\hook\LifecycleHook')){
    \pms\hook\LifecycleHook::mount(LIFECYCLE_BOOT,function () {
        $dbConfig = config('database');
        if ($dbConfig !== null) {
            \think\facade\Db::setConfig($dbConfig);
        }
    });
}

if (in_swoole()) {
    if(class_exists('\pms\hook\SwooleHttpLifecycleHook')){
        \pms\hook\SwooleHttpLifecycleHook::mount(LIFECYCLE_BOOT, function () {
            $dbConfig = config('database');
            if ($dbConfig !== null) {
                foreach ($dbConfig['connections'] ?? [] as $key => $value){
                    $type = $value['type'] ?? 'mysql';
                    switch ($type){
                        case 'mysql':
                            $dbConfig['connections'][$key]['type'] = \pms\program\database\connector\MysqlPool::class;
                            $dbConfig['connections'][$key]['builder'] = \think\db\builder\Mysql::class;
                            break;
                    }
                }
                \think\facade\Db::setConfig($dbConfig);
            }
        });
        \pms\hook\SwooleHttpLifecycleHook::mount(SWOOLE_LIFECYCLE_HTTP_REQUEST_DESTRUCT, function () {
            try {
                pdb_pool_autoclose();
            } catch (\Throwable $e) {
                echo "数据库连接错误：" . $e->getMessage() . "\r\n";
            }
        });

    }

}