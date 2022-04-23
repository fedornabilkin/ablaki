<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 19:23
 */

namespace common\middleware;

use common\models\user\Person;
use yii\base\BaseObject;
use yii\db\ActiveRecord;

class DataMiddleware extends BaseObject
{
    /** @var Person */
    public $user;

    /** @var float */
    public $changingBalance = 0.0;
    /** @var float */
    public $changingCredit = 0.0;
    /** @var float */
    public $changingRating = 0.0;
    /** @var int */
    public $changingBonusCount = 0;

    /** @var float */
    public $commissionAmount = 0.0;

    /** @var string */
    public $historyType = 'other';
    /** @var string */
    public $historyComment = 'other';
    /**
     * @var ActiveRecord
     */
    protected $model;

    public function needUpdatePersonCounters(): bool
    {
        foreach ($this->getUpdatePersonCounters() as $cnt) {
            if ($cnt > 0) {
                return true;
            }
        }
        return false;
    }

    public function getUpdatePersonCounters(): array
    {
        return [
            'balance' => $this->changingBalance,
            'credit' => $this->changingCredit,
            'rating' => $this->changingRating,
            'bonus_count' => $this->changingBonusCount,
        ];
    }

    public function getNeedCredit(): int
    {
        return 0;
    }

    public function getNeedBalance(): int
    {
        return 0;
    }

    /**
     * @param Person $user
     */
    public function setUser(Person $user): void
    {
        $this->user = $user;
    }

    /**
     * @return Person
     */
    public function getUser(): Person
    {
        return $this->user;
    }

    /**
     * @param ActiveRecord $model
     * @return void
     */
    public function setModel(ActiveRecord $model): void
    {
        $this->model = $model;
    }

    /**
     * @return ActiveRecord
     */
    public function getModel(): ActiveRecord
    {
        return $this->model;
    }
}
