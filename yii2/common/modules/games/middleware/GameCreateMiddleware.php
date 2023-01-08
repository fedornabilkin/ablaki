<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 15:32
 */

namespace common\modules\games\middleware;

use common\middleware\AbstractCreateMiddleware;

class GameCreateMiddleware extends AbstractCreateMiddleware
{
    /** @var GameDataMiddleware */
    public static $data;

    protected $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->game;
        $this->updateData();
        if (!$this->create()) {
            return $this->stopProcessing('Error create game');
        }

        return parent::check();
    }

    public function updateData(): void
    {
        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Create game ' . $this->count() . 'x' . self::$data->getKon();
    }

    public function getRow(): array
    {
        return [
            'kon' => self::$data->getKon(),
            'user_id' => self::$data->user->user_id,
            'user_gamer' => 0,
            'created_at' => time(),
        ];
    }

    public function count(): int
    {
        return self::$data->getCount();
    }

    protected function getTableName(): string
    {
        return $this->model::tableName();
    }
}
