<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:07
 */

namespace common\models\history;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

class HistoryBalanceQuery extends ActiveQuery
{
    use UserQueryTrait;

    public function byEveryday(): self
    {
        return $this->byType('everyday');
    }

    public function byType(string $type): self
    {
        return $this->andWhere(['type' => $type]);
    }
}
