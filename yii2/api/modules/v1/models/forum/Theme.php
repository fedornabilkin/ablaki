<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:12
 */

namespace api\modules\v1\models\forum;

use common\modules\forum\models\ForumTheme;
use yii\db\Query;
use common\services\user\PublicProfile;

class Theme extends ForumTheme
{
    public $comment_count;
    public $last_comment_text;
    public $last_comment_username;
    public $last_comment_created_at;
    public $last_comment_sort;
    public $first_comment_user_id;
    public $first_comment_username;

    public static function find(): \yii\db\ActiveQuery
    {
        $count = (new \yii\db\Query())->select('COUNT(*)')->from('forum_comment')
            ->where('[[forum_comment.theme_id]] = [[forum_theme.id]]')->andWhere(['active' => 1]);
        $lastText = self::latestCommentQuery('fc.comment');
        $lastCreatedAt = self::latestCommentQuery('fc.created_at');
        $lastUsername = self::latestCommentQuery('u.username')->leftJoin(['u' => 'user'], 'u.id = fc.user_id');
        $firstUserId = self::firstCommentQuery('fc.user_id');
        $firstUsername = self::firstCommentQuery('u.username')->leftJoin(['u' => 'user'], 'u.id = fc.user_id');
        return parent::find()->select([
            'forum_theme.*',
            'comment_count' => $count,
            'last_comment_text' => $lastText,
            'last_comment_username' => $lastUsername,
            'last_comment_created_at' => $lastCreatedAt,
            'last_comment_sort' => (clone $count)->select(new \yii\db\Expression('COALESCE(MAX([[forum_comment.created_at]]), 0)')),
            'first_comment_user_id' => $firstUserId,
            'first_comment_username' => $firstUsername,
        ])->with(['user.person', 'firstAuthor.person']);
    }

    private static function latestCommentQuery(string $column): Query
    {
        return (new Query())->select($column)
            ->from(['fc' => 'forum_comment'])
            ->where('fc.theme_id = forum_theme.id')
            ->andWhere(['fc.active' => 1])
            ->orderBy(['fc.created_at' => SORT_DESC, 'fc.id' => SORT_DESC])
            ->limit(1);
    }

    public function fields(): array
    {
        $fields = parent::fields();
        $fields['user'] = static function ($model) {
            return PublicProfile::fromUser($model->user ?: $model->firstAuthor);
        };
        $fields['comment_count'] = function () {
            return (int)($this->comment_count ?? $this->getForumComments()->andWhere(['active' => 1])->count());
        };
        $fields['last_comment_text'] = static function ($model) {
            return $model->last_comment_text === null ? null : trim($model->last_comment_text);
        };
        $fields['last_comment_username'] = static function ($model) {
            return $model->last_comment_username === null ? null : trim($model->last_comment_username);
        };
        $fields['last_comment_created_at'] = static function ($model) {
            return $model->last_comment_created_at === null ? null : (int)$model->last_comment_created_at;
        };
        $fields['first_comment_user_id'] = static function ($model) {
            return $model->first_comment_user_id === null ? null : (int)$model->first_comment_user_id;
        };
        $fields['first_comment_username'] = static function ($model) {
            return $model->first_comment_username === null ? null : trim($model->first_comment_username);
        };
        return $fields;
    }

    private static function firstCommentQuery(string $column): Query
    {
        return (new Query())->select($column)
            ->from(['fc' => 'forum_comment'])
            ->where('fc.theme_id = forum_theme.id')
            ->andWhere(['fc.active' => 1])
            ->orderBy(['fc.created_at' => SORT_ASC, 'fc.id' => SORT_ASC])
            ->limit(1);
    }

    public function extraFields(): array
    {
        return ['first_comment' => static function ($model) {
            $comment = $model->firstComment;
            if ($comment === null) return null;
            return array_merge($comment->toArray(), ['user' => PublicProfile::fromUser($comment->user)]);
        }];
    }

    public function getFirstAuthor(): \yii\db\ActiveQuery
    {
        return $this->hasOne(\common\models\user\User::class, ['id' => 'first_comment_user_id']);
    }

    public function getFirstComment(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Comment::class, ['theme_id' => 'id'])->andWhere(['active' => 1])
            ->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->with('user.person');
    }

    public function beforeValidate()
    {
        if ($this->isNewRecord) $this->view = 0;
        return parent::beforeValidate();
    }

    public function scenarios(): array
    {
        return array_merge(parent::scenarios(), ['create' => ['title'], 'update' => ['title']]);
    }

}
