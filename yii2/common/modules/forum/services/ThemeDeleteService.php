<?php

namespace common\modules\forum\services;

use common\modules\forum\models\ForumTheme;
use yii\db\Connection;
use yii\db\Query;
use yii\web\NotFoundHttpException;

class ThemeDeleteService
{
    public static function delete(Connection $db, int $id): void
    {
        $db->transaction(static function () use ($db, $id) {
            if (!ForumTheme::findOne($id)) throw new NotFoundHttpException();
            $comments = (new Query())->select('id')->from('{{%forum_comment}}')->where(['theme_id' => $id]);
            $db->createCommand()->delete('{{%forum_comment_gift}}', ['comment_id' => $comments])->execute();
            $db->createCommand()->delete('{{%forum_comment}}', ['theme_id' => $id])->execute();
            $db->createCommand()->delete('{{%forum_theme}}', ['id' => $id])->execute();
        });
    }
}
