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
            // 闲置过期的连接按坏连接协议归还null，池会丢弃它并补建新连接
            $this->pool[$dsn]->put(null);
            $pdo = $this->getRealPdo($dsn);
        } else {
            @$pdo->last_time = time() + (($this->config['pool_wait_idle_time'] ?? 28800) - 10);
        }
        @$pdo->_dsn = $dsn;
        return $pdo;
    }

    /**
     * 断线判定：命中断线特征时给当前连接标记坏连接，归还环节据此丢弃而不是放回池中复用。
     */
    protected function isBreak($e): bool
    {
        $break = parent::isBreak($e);
        if ($break && $this->linkID instanceof \PDO) {
            @$this->linkID->_broken = true;
        }
        return $break;
    }

    public function close(): void
    {
        foreach ($this->links as $link) {
            // 坏连接按协议归还null触发丢弃与补建，健康连接正常归还复用
            $this->pool[$link->_dsn]->put(!empty($link->_broken) ? null : $link);
        }
        parent::close();
    }
}
