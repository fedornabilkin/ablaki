<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:07
 */

namespace common\models\history;

use yii\db\ActiveQuery;
use yii\web\IdentityInterface;

class HistoryBalanceQuery extends ActiveQuery
{
    public function my(IdentityInterface $identity): self
    {
        return $this->andWhere(['user_id' => $identity->getId()]);
    }

    public function byEveryday(): self
    {
        return $this->byType('everyday');
    }

    public function byType(string $type): self
    {
        return $this->andWhere(['type' => $type]);
    }
}
