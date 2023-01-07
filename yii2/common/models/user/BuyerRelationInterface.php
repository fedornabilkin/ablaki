<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 14:40
 */

namespace common\models\user;

use yii\db\ActiveQuery;

/**
 * @property $userBuyer
 */
interface BuyerRelationInterface
{
    public function getUserBuyer(): ActiveQuery;
}
