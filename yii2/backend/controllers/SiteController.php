<?php
namespace backend\controllers;

use backend\models\user\LoginForm;
use common\models\Todo;
use common\models\user\Person;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['login', 'error', 'login-key'],
                        'allow' => true,
                    ],
                    [
                        'actions' => ['logout', 'index'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
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
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex()
    {
        $todoProvider = new ActiveDataProvider([
            'query' => Todo::find()->where(['status' => 0])->orderBy(['id' => SORT_ASC]),
            'pagination' => [
                'pageSize' => 5,
            ],
        ]);

        $personProvider = new ActiveDataProvider([
            'query' => Person::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => 5,
            ],
        ]);

        return $this->render('index', [
            'todoProvider' => $todoProvider,
            'personProvider' => $personProvider,
        ]);
    }

    public function actionLoginKey($key)
    {
        $model = Yii::createObject(LoginForm::class);
        if ($model->loginKey($key)) {
            return $this->goBack();
        }

        $response['errors'] = 'invalid authenticate';
        return $response;
    }
}
