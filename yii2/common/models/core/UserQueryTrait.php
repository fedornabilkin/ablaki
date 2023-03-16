<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 16.03.2023
 * Time: 20:26
 */

namespace common\models\core;

use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

/**
 * @mixin ActiveQuery
 */
trait UserQueryTrait
{
    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere(['user_id' => $identity->getId()]);
    }
}
