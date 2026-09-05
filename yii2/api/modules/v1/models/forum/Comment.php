<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:13
 */

namespace api\modules\v1\models\forum;

use api\modules\v1\models\User;
use common\modules\forum\models\ForumComment;
use yii\db\ActiveQuery;
use yii\db\Query;
use yii\db\Expression;
use Yii;

class Comment extends ForumComment
{
    public $gift_count = 0;
    public $gifted_by_me = 0;

    public static function find(): ActiveQuery
    {
        $viewer = Yii::$app && Yii::$app->has('user') ? (int)Yii::$app->user->id : 0;
        $gifts = (new Query())->select(new Expression('COUNT(*)'))->from('forum_comment_gift')
            ->where(new Expression('[[comment_id]] = [[forum_comment.id]]'));
        $mine = (clone $gifts)->andWhere(['user_id' => $viewer]);
        return parent::find()->select(['forum_comment.*', 'gift_count' => $gifts, 'gifted_by_me' => $mine]);
    }

    public function fields(): array
    {
        return array_merge(parent::fields(), [
            'gift_count' => function () { return (int)$this->gift_count; },
            'gifted_by_me' => function () { return (bool)$this->gifted_by_me; },
        ]);
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), ['create' => ['comment', 'theme_id'], 'update' => ['comment']]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [[['theme_id'], 'required', 'on' => 'create']]);
    }

    public function extraFields(): array
    {
        return ['theme', 'user'];
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
