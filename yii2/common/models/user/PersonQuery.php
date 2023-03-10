<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 10.03.2023
 * Time: 20:13
 */

namespace common\models\user;

use yii\db\ActiveQuery;

class PersonQuery extends ActiveQuery
{
    public function noCleaning(): self
    {
        return $this->andWhere(['<', 'last_cleaning_at', 1]);
    }

    public function isCleaning(): self
    {
        return $this->andWhere(['>', 'last_cleaning_at', 1]);
    }

    public function noRating(): self
    {
        return $this->andWhere(['<', 'rating', 0.01]);
    }

    public function noRefovod(): self
    {
        return $this->andWhere(['<', 'refovod', 1]);
    }
}
