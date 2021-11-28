<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 23.10.2021
 * Time: 0:20
 */

namespace common\modules\games\models\repo;

use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

class OrelQuery extends ActiveQuery
{
    public function free(): self
    {
        return $this->andWhere(['user_gamer' => 0]);
    }

    public function notFree(): self
    {
        return $this->andWhere(['>', 'user_gamer', 0]);
    }

    public function my(IdentityInterface $userIdentity): self
    {
        return $this->andWhere(['user_id' => $userIdentity->getId()]);
    }

    public function history(IdentityInterface $userIdentity): self
    {
        return $this->andWhere(['or', "user_id={$userIdentity->getId()}", "user_gamer={$userIdentity->getId()}"]);
    }

    public function notMy(IdentityInterface $userIdentity): self
    {
        return $this->andWhere(['!=', 'user_id', $userIdentity->getId()]);
    }
}
