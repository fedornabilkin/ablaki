<?php

namespace common\modules\exchange\models;

use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

/**
 * @see CreditExchange
 */
class CreditExchangeQuery extends ActiveQuery
{
    protected $userFieldName = 'user_id';

    protected $clientFieldName = 'user_buyer';

    public function list(IdentityInterface $identity): self
    {
        return $this->free()
            ->notMy($identity);
    }

    public function listMy(IdentityInterface $identity): self
    {
        return $this->free()
            ->my($identity);
    }

    public function listHistory(IdentityInterface $identity): self
    {
        return $this->notFree()
            ->andWhere([
                'or',
                "{$this->getUserFieldName()}={$identity->getId()}",
                "{$this->getClientFieldName()}={$identity->getId()}"
            ]);
    }

    public function onlySell(): self
    {
        return $this->withType($this->modelClass::EX_TYPE_SELL);
    }

    public function onlyBuy(): self
    {
        return $this->withType($this->modelClass::EX_TYPE_BUY);
    }

    public function withType(string $type): self
    {
        return $this->andWhere(['type' => $type]);
    }

    public function free(): self
    {
        return $this->andWhere(['or', ['<', $this->getClientFieldName(), 1], [$this->getClientFieldName() => null]]);
    }

    public function notFree(): self
    {
        return $this->andWhere(['>', $this->getClientFieldName(), 0]);
    }

    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere([$this->getUserFieldName() => $identity->getId()]);
    }

    public function notMy(IdentityInterface $identity): self
    {
        return $this->andWhere(['!=', $this->getUserFieldName(), $identity->getId()]);
    }

    /**
     * @return string
     */
    public function getUserFieldName(): string
    {
        return $this->userFieldName;
    }

    /**
     * @return string
     */
    private function getClientFieldName(): string
    {
        return $this->clientFieldName;
    }
}
