<?php
namespace backend\controllers;

use common\services\auth\TelegramConfig;
use common\services\auth\TelegramSettings;
use Yii;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\BadRequestHttpException;

final class AuthSettingsController extends Controller
{
    public function behaviors(): array
    {
        return ['verbs' => ['class' => \yii\filters\VerbFilter::class, 'actions' => ['index' => ['GET', 'POST']]]];
    }

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest || !Yii::$app->user->can('p-admin')) throw new ForbiddenHttpException();
        return parent::beforeAction($action);
    }

    public function actionIndex()
    {
        $settings = new TelegramSettings(Yii::$app->db);
        if (Yii::$app->request->isPost) {
            $enabled = Yii::$app->request->post('enabled');
            if (!in_array($enabled, ['0', '1'], true)) throw new BadRequestHttpException('Некорректное значение.');
            $settings->setEnabled($enabled === '1');
            Yii::info(['action' => 'auth.telegram.configure', 'enabled' => $enabled === '1', 'user_id' => Yii::$app->user->id], 'admin.settings');
            Yii::$app->session->setFlash('success', 'Настройки входа сохранены.');
            return $this->refresh();
        }
        return $this->render('index', ['settings' => $settings->read(), 'available' => TelegramConfig::fromApplication()->available()]);
    }
}
