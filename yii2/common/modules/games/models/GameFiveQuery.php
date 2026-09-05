<?php

namespace common\modules\games\models;

use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

class GameFiveQuery extends ActiveQuery
{
    /**
     * Свободные чужие партии — к ним можно присоединиться.
     */
    public function listGame(IdentityInterface $identity): self
    {
        return $this->free()
            ->notMy($identity);
    }

    /**
     * Мои незавершённые партии: свободные и идущие.
     */
    public function listMyGame(IdentityInterface $identity): self
    {
        return $this->andWhere(['status' => [GameFive::STATUS_FREE, GameFive::STATUS_PLAY]])
            ->participant($identity);
    }

    /**
     * Завершённые партии с моим участием.
     */
    public function listHistory(IdentityInterface $identity): self
    {
        return $this->andWhere(['status' => [GameFive::STATUS_USER, GameFive::STATUS_GAMER]])
            ->participant($identity);
    }

    public function free(): self
    {
        return $this->andWhere(['status' => GameFive::STATUS_FREE]);
    }

    public function participant(IdentityInterface $identity): self
    {
        return $this->andWhere([
            'or',
            ['user_id' => $identity->getId()],
            ['user_gamer' => $identity->getId()],
        ]);
    }

    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere(['user_id' => $identity->getId()]);
    }

    public function notMy(IdentityInterface $identity): self
    {
        return $this->andWhere(['!=', 'user_id', $identity->getId()]);
    }
}
