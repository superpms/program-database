<?php
if (!function_exists('in_swoole')) {
    function in_swoole(): bool
    {
        return defined('SWOOLE_VERSION') && SWOOLE_VERSION !== null;
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