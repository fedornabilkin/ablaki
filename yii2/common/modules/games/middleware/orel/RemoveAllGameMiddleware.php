<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 20:40
 */

namespace common\modules\games\middleware\orel;

use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameOrel;

class RemoveAllGameMiddleware extends GameMiddleware
{
    protected $where;
    protected $sum = 0;
    protected $modelClass = GameOrel::class;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        try {
            $this->execute();
        } catch (\Exception $e) {
            $this->stopProcessing('Error remove');
        }

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
            self::$data->changingCredit = $this->sum;
            self::$data->historyType = call_user_func([$this->modelClass, 'getHistoryType']);
            self::$data->historyComment = 'Remove all game';
        }
    }

    public function remove()
    {
        call_user_func([$this->modelClass, 'deleteAll'], $this->where);
    }
}
