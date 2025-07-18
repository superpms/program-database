<?php
\pms\hook\LifecycleHook::mount(function () {
    $dbConfig = config('database');
    if ($dbConfig !== null) {
        \think\facade\Db::setConfig($dbConfig);
    }
});

if (in_swoole()) {
    if (defined(SWOOLE_HTTP_LIFECYCLE_START)) {
        \pms\hook\SwooleHttpLifecycleHook::mount(SWOOLE_HTTP_LIFECYCLE_START, function () {
            $dbConfig = config('database');
            if ($dbConfig !== null) {
                foreach ($dbConfig['connections'] ?? [] as $key => $value){
                    $type = $value['type'] ?? 'mysql';
                    switch ($type){
                        case 'mysql':
                            $dbConfig['connections'][$key]['type'] = PDB_MYSQL_POOL_CONNECTOR;
                            $dbConfig['connections'][$key]['builder'] = PDB_MYSQL_BUILDER;
                            break;
                    }
                }
                \think\facade\Db::setConfig($dbConfig);
            }
        });
        \pms\hook\SwooleHttpLifecycleHook::mount(SWOOLE_HTTP_LIFECYCLE_REQUEST_DESTRUCT, function () {
            try {
                pdb_pool_autoclose();
            } catch (\Throwable $e) {
                echo "数据库连接错误：" . $e->getMessage() . "\r\n";
            }
        });
    }
}