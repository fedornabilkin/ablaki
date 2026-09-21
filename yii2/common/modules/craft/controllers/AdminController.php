<?php
namespace common\modules\craft\controllers;
class AdminController extends \yii\web\Controller
{
    // mdm route ACL delegates these actions to the stricter module-wide p-admin gate below.
    public function allowAction(): array { return ['index','view','update','delete','edit','preview','import','export','template','settings','craft','add-item']; }
    public function beforeAction($action)
    {
        if (\Yii::$app->id!=='app-backend'||\Yii::$app->user->isGuest||!\Yii::$app->user->can('p-admin')) throw new \yii\web\ForbiddenHttpException('Требуются права администратора.');
        return parent::beforeAction($action);
    }
}
