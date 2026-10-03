<?php
namespace common\modules\economy\service;

use yii\db\Connection;
use yii\db\Transaction;

/** Consistent HTTP financial reads, without registry locks or ledger mutations. */
class FinanceReadSnapshot
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function run(callable $read): array
    {
        if ($this->db->getTransaction()) throw new \LogicException('Financial snapshot must start before any transaction.');
        return $this->db->transaction(function () use ($read) {
            // Yii sets the begin() isolation argument before BEGIN. PostgreSQL needs it inside.
            if ($this->db->driverName === 'pgsql') $this->db->getTransaction()->setIsolationLevel(Transaction::REPEATABLE_READ);
            return $read();
        }, $this->db->driverName === 'pgsql' ? null : ($this->db->driverName === 'sqlite' ? Transaction::SERIALIZABLE : Transaction::REPEATABLE_READ));
    }
}
