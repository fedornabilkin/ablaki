<?php
namespace api\controllers;

use api\filters\Auth;
use Yii;
use yii\base\Exception;
use yii\base\UserException;
use yii\rest\Controller;
use api\models\LoginForm;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => Auth::class,
            'except' => ['login'],
            'only' => ['logout'],
        ];
        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * Logs in a user.
     *
     * @return mixed
     * @throws Exception
     */
    public function actionLogin()
    {
        $model = \Yii::createObject(LoginForm::class);
        if ($model->load(Yii::$app->request->post(), '') && $model->login()) {
            return [
                'user' => $model->getUser(),
                'token' => $model->token,
            ];
        } else {
            throw new UserException('No auth', '401');
        }
    }

    /**
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function actionLogout()
    {
        $model = \Yii::createObject(LoginForm::class);
        return $model->logout();
    }
}
