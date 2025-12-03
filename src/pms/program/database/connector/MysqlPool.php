<?php

namespace pms\program\database\connector;

use Swoole\ConnectionPool;
use think\db\connector\Mysql as MysqlConnector;

class MysqlPool extends MysqlConnector{


    /**
     * @var ConnectionPool[] $pool
     */
    protected array $pool = [];

    protected function createPdo($dsn, $username, $password, $params)
    {
        if (!isset($this->pool[$dsn])) {
            $this->pool[$dsn] = new ConnectionPool(function ()use($dsn, $username, $password, $params){
                return parent::createPdo($dsn, $username, $password, $params);
            },$this->config['pool_count'] ?? 64);
        }
        return $this->getRealPdo($dsn);
    }

    /**
     * 获取存活的PDO连接
     * @param $dsn
     * @return mixed
     */
    protected function getRealPdo($dsn):\PDO{
        /**
         * @var \PDO $pdo
         */
        $pdo = $this->pool[$dsn]->get();
        if (isset($pdo->last_time) && $pdo->last_time <= time()) {
            $pdo = null;
            $this->pool[$dsn]->put($pdo);
            $pdo = $this->getRealPdo($dsn);
        } else {
            @$pdo->last_time = time() + (($this->config['pool_wait_idle_time'] ?? 28800) - 10);
        }
        @$pdo->_dsn = $dsn;
        return $pdo;
    }

    public function close(): void
    {
        foreach ($this->links as $link) {
            $this->pool[$link->_dsn]->put($link);
        }
        parent::close();
    }
}