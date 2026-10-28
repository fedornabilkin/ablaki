<?php
namespace common\modules\world\models\admin;

final class AdminAccess
{
    public static function requirePermission(): void
    {
        if (\Yii::$app->id !== 'app-backend' || \Yii::$app->user->isGuest || !\Yii::$app->user->can('p-admin'))
            throw new \yii\web\ForbiddenHttpException('Требуются права администратора.');
    }
}
