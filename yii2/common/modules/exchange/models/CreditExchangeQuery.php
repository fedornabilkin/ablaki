<?php

namespace common\modules\exchange\models;

use common\models\core\UserFieldQueryTrait;
use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

/**
 * @see CreditExchange
 */
class CreditExchangeQuery extends ActiveQuery
{
    use UserFieldQueryTrait;

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
                "{$this->userFieldName()}={$identity->getId()}",
                "{$this->buyerFieldName()}={$identity->getId()}"
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
        return $this->andWhere(['or', ['<', $this->buyerFieldName(), 1], [$this->buyerFieldName() => null]]);
    }

    public function notFree(): self
    {
        return $this->andWhere(['>', $this->buyerFieldName(), 0]);
    }

    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere([$this->userFieldName() => $identity->getId()]);
    }

    public function notMy(IdentityInterface $identity): self
    {
        return $this->andWhere(['!=', $this->userFieldName(), $identity->getId()]);
    }
}
