<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 19:23
 */

namespace common\middleware;

use common\middleware\dto\Request;
use common\models\user\Person;
use yii\base\BaseObject;

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
     * @var Request
     */
    protected $request;

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
     * @param Request $request
     * @return void
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }
}
