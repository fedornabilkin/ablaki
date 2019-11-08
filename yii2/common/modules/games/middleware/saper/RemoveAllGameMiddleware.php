<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:35
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameSaper;

class RemoveAllGameMiddleware extends GameMiddleware
{
    protected $where;
    protected $sum = 0;
    protected $modelClass = GameSaper::class;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->execute();

        return parent::check();
    }

    public function execute()
    {
        $this->updateData();
        $this->remove();
    }

    public function updateData()
    {
        $this->where = ['user_id' => self::$data->user->user_id, 'user_gamer' => 0];

        $query = call_user_func([$this->modelClass, 'find']);
        $this->sum = $query->where($this->where)->sum('kon');

        if ($this->sum > 0) {
            self::$data->changingBalance = $this->sum;
            self::$data->historyType = call_user_func([$this->modelClass, 'getHistoryType']);
            self::$data->historyComment = 'Remove all game';
        }
    }

    public function remove()
    {
        call_user_func([$this->modelClass, 'deleteAll'], $this->where);
    }
}
