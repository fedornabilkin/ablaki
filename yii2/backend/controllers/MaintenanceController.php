<?php
namespace backend\controllers;

use mdm\admin\components\Helper;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;

class MaintenanceController extends Controller
{
    public function behaviors(): array
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['clear-cache' => ['POST']]]];
    }

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) {
            throw new ForbiddenHttpException('Войдите в административную панель.');
        }
        return parent::beforeAction($action);
    }

    public function actionClearCache()
    {
        // Invalidate the actual consumers, not Redis FLUSHDB or session/queue storage.
        Yii::$app->authManager->invalidateCache();
        Helper::invalidate();
        Yii::info(['action' => 'admin.cache.clear', 'user_id' => (int)Yii::$app->user->id], 'admin.maintenance');
        Yii::$app->session->setFlash('success', 'Кэш прав доступа и меню сброшен.');
        return $this->redirect(['/site/index']);
    }
}
