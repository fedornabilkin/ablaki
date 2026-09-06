<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:12
 */

namespace api\modules\v1\models\forum;

use common\modules\forum\models\ForumTheme;

class Theme extends ForumTheme
{
    public $comment_count;

    public static function find(): \yii\db\ActiveQuery
    {
        $count = (new \yii\db\Query())->select('COUNT(*)')->from('forum_comment')
            ->where('[[forum_comment.theme_id]] = [[forum_theme.id]]')->andWhere(['active' => 1]);
        return parent::find()->select(['forum_theme.*', 'comment_count' => $count]);
    }

    public function fields(): array
    {
        $fields = parent::fields();
        $fields['comment_count'] = function () {
            return (int)($this->comment_count ?? $this->getForumComments()->andWhere(['active' => 1])->count());
        };
        return $fields;
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
