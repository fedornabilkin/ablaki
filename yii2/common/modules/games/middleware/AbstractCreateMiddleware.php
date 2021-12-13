<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 15:32
 */

namespace common\modules\games\middleware;

use Yii;

abstract class AbstractCreateMiddleware extends GameMiddleware
{
    abstract public function getRow(): array;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->updateData();
        if (!$this->create()) {
            return $this->stopProcessing('Error create game');
        }

        return parent::check();
    }

    public function updateData(): void
    {
        self::$data->historyType = self::$data->game->getHistoryType();
        self::$data->historyComment = 'Create game ' . self::$data->game->count . 'x' . self::$data->game->kon;
    }

    public function create(): int
    {
        $rows = [];

        for ($i = 1; $i <= self::$data->game->count; $i++) {
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

    protected function getTableName(): string
    {
        return self::$data->game::tableName();
    }
}
