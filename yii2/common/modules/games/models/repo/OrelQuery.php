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
    public function listGame(IdentityInterface $identity): self
    {
        return $this->free()
            ->notMy($identity);
    }

    public function listMyGame(IdentityInterface $identity): self
    {
        return $this->free()
            ->my($identity);
    }

    public function listHistory(IdentityInterface $identity): self
    {
        return $this->notFree()
            ->andWhere(['or', "user_id={$identity->getId()}", "user_gamer={$identity->getId()}"]);
    }

    public function free(): self
    {
        return $this->andWhere(['user_gamer' => 0]);
    }

    public function notFree(): self
    {
        return $this->andWhere(['>', 'user_gamer', 0]);
    }

    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere(['user_id' => $identity->getId()]);
    }

    public function iGamer(IdentityInterface $identity): self
    {
        return $this->andWhere(['user_gamer' => $identity->getId()]);
    }

    public function notMy(IdentityInterface $identity): self
    {
        return $this->andWhere(['!=', 'user_id', $identity->getId()]);
    }
}
