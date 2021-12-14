<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 22:45
 */

namespace common\models\user;

use yii\db\ActiveQuery;

/**
 * @property User $user
 */
interface UserRelationInterface
{
    public function getUser(): ActiveQuery;
}
