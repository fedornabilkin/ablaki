<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 12:55
 */

namespace common\modules\exchange\models;

use common\models\core\UserFieldQueryTrait;
use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

class CreditTransferQuery extends ActiveQuery
{
    use UserFieldQueryTrait;

    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere([$this->userFieldName() => $identity->getId()]);
    }

    public function free(): self
    {
        return $this->andWhere(['or', ['<', $this->buyerFieldName(), 1], [$this->buyerFieldName() => null]]);
    }

    public function notFree(): self
    {
        return $this->andWhere(['>', $this->buyerFieldName(), 0]);
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
}
