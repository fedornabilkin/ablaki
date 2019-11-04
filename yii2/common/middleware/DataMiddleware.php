<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 19:23
 */

namespace common\middleware;

use common\models\user\Person;
use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;
use yii\base\BaseObject;
use yii\db\Transaction;

class DataMiddleware extends BaseObject
{
    /** @var Person */
    public $user;
    /** @var GameSaper|GameOrel */
    public $game;

    /** @var float */
    public $changingBalance = 0.0;
    /** @var float */
    public $changingCredit = 0.0;
    /** @var float */
    public $changingRating = 0.0;
    /** @var int */
    public $changingBonusCount = 0;

    /** @var float */
    public $commissionAmout = 0.0;

    /** @var string */
    public $historyType = 'other';
    /** @var string */
    public $historyComment = 'other';
    /** @var Transaction */
    private $transaction;

    const COMMISSION_CREDIT = 'credit';

    public function init()
    {
        parent::init();

        if (!$this->transaction) {
            $this->transaction = $this->user::getDb()->beginTransaction();
        }
    }

    /**
     * @return Transaction
     */
    public function getTransaction()
    {
        return $this->transaction;
    }

    public function getUpdatePersonCounters()
    {
        return [
            'balance' => $this->changingBalance,
            'credit' => $this->changingCredit,
            'rating' => $this->changingRating,
            'bonus_count' => $this->changingBonusCount,
        ];
    }
}
