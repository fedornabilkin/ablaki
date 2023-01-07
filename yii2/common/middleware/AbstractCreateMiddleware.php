<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 23:45
 */

namespace common\middleware;

use Yii;

abstract class AbstractCreateMiddleware extends AbstractMiddleware
{

    abstract public function getRow(): array;

    abstract public function getCount(): int;

    abstract protected function getTableName(): string;

    public function create(): int
    {
        $rows = [];

        for ($i = 1; $i <= $this->getCount(); $i++) {
            $rows[] = $this->getRow();
        }

        return $this->batch($rows);
    }

    public function batch($rows): int
    {
        return Yii::$app->db->createCommand()
            ->batchInsert($this->getTableName(), array_keys($rows[0]), $rows)
            ->execute();
    }
}
