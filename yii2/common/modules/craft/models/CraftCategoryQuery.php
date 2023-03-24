<?php

namespace common\modules\craft\models;

use yii\db\ActiveQuery;

/**
 * This is the ActiveQuery class for [[CraftCategory]].
 *
 * @see CraftCategory
 */
class CraftCategoryQuery extends ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    /**
     * {@inheritdoc}
     * @return CraftCategory[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return CraftCategory|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
