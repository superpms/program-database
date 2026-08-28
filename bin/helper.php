<?php
if (!function_exists('in_swoole')) {
    function in_swoole(): bool
    {
        return defined('SWOOLE_VERSION') && SWOOLE_VERSION !== null;
    }
}
if (!function_exists('pdb_apply_break_reconnect')) {
    /**
     * 为全部连接配置强制开启断线重连。
     * 断线检测与坏连接丢弃是连接层固有职责，不依赖各项目自行配置 break_reconnect。
     */
    function pdb_apply_break_reconnect(array &$dbConfig): void
    {
        foreach ($dbConfig['connections'] ?? [] as $key => $value) {
            $dbConfig['connections'][$key]['break_reconnect'] = true;
        }
    }
}
function pdb_pool_autoclose(): void{
    $connector = \think\facade\Db::getInstance();
    if(!empty($connector)){
        foreach ($connector as $value){
            $value->close();
        }
    }
}