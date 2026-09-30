<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 20:18
 */

namespace common\modules\games\behaviors;


use common\behaviors\AbstractBehavior;

/**
 * @deprecated
 */
class AbstractGameBehavior extends AbstractBehavior
{

    protected function getUserCredit()
    {
        return $this->owner->personInstance->credit;
    }

    protected function getUserBalance()
    {
        return $this->owner->personInstance->balance;
    }
}
