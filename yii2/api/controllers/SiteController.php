<?php
namespace api\controllers;

use api\filters\Auth;
use api\models\LoginForm;
use dektrium\user\models\RegistrationForm;
use dektrium\user\Module;
use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\UserException;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

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
        $model = Yii::createObject(LoginForm::class);
        if ($model->load(Yii::$app->request->post(), '') && $model->login()) {
            return [
                'user' => $model->getUser(),
                'token' => $model->token,
            ];
        }

        throw new UserException('No auth', '401');
    }

    /**
     * @return bool
     * @throws InvalidConfigException
     */
    public function actionLogout()
    {
        $model = Yii::createObject(LoginForm::class);
        return $model->logout();
    }

    /**
     * @return array
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     */
    public function actionRegistration(): array
    {
        $response['result'] = false;

        /** @var Module $module */
        $module = Yii::$app->getModule('user');
        if ($module && !$module->enableRegistration) {
            throw new NotFoundHttpException();
        }

        /** @var RegistrationForm $model */
        $model = Yii::createObject(RegistrationForm::class);

        if ($model->load(Yii::$app->request->getBodyParams(), '') && $model->register()) {
            $response['result'] = Yii::$app->session->getFlash('info');
            $response['user'] = ['username' => $model->username, 'email' => $model->email];
        } elseif (!$model->validate() || $model->hasErrors()) {
            $response['errors'] = $model->getFirstErrors();
        }

//        defined( 'STDIN' ) or define( 'STDIN', fopen( 'php://stdin', 'r' ) );
//        defined( 'STDOUT' ) or define( 'STDOUT', fopen( 'php://stdout', 'w' ) );

//        $post = Yii::$app->request->post();
//        $ctrl = new CreateController('Registration', Yii::$app);
//        $ctrl->actionIndex($post['email'], $post['username'], $post['password']);

        return $response;
    }
}
