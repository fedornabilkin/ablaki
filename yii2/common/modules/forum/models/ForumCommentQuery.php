<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 16.03.2023
 * Time: 20:21
 */

namespace common\modules\forum\models;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

class ForumCommentQuery extends ActiveQuery
{
    use UserQueryTrait;

    public function prepare($builder)
    {
        $query = parent::prepare($builder);
        if (\Yii::$app instanceof \yii\web\Application && \Yii::$app->user->isGuest) {
            list(, $alias) = $this->getTableNameAndAlias();
            $public = (new \yii\db\Query())->select('id')->from('forum_theme')->where(['is_private' => 0]);
            $query->andWhere(['in', $alias . '.theme_id', $public]);
        }
        return $query;
    }
}
