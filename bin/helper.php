<?php
function pdb_pool_autoclose(): void{
    $connector = \think\facade\Db::getInstance();
    if(!empty($connector)){
        foreach ($connector as $value){
            $value->close();
        }
    }
}