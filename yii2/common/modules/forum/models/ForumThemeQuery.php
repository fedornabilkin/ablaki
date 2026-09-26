<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 16.03.2023
 * Time: 20:22
 */

namespace common\modules\forum\models;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

class ForumThemeQuery extends ActiveQuery
{
    use UserQueryTrait;

    public function prepare($builder)
    {
        $query = parent::prepare($builder);
        if (\Yii::$app instanceof \yii\web\Application && \Yii::$app->user->isGuest) {
            list(, $alias) = $this->getTableNameAndAlias();
            $query->andWhere([$alias . '.is_private' => 0]);
        }
        return $query;
    }
}
