<?php
namespace backend\controllers;

use backend\models\user\LoginForm;
use common\models\Todo;
use common\models\user\Person;
use common\modules\forum\models\ForumTheme;
use common\services\user\UserClearService;
use DateTimeImmutable;
use Yii;
use yii\base\Exception;
use yii\data\ActiveDataProvider;
use yii\db\Query;
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
        $dt = new DateTimeImmutable('today');
        $todayStamp = $dt->getTimestamp();

        $userCleanProvider = new ActiveDataProvider([
            'query' => (new UserClearService())->clearQuery()->orderBy(['id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => 15,
            ],
        ]);


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

        $themeProvider = new ActiveDataProvider([
            'query' => ForumTheme::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => 5,
            ],
        ]);

        $commission = (new Query())
            ->from('comission')
            ->select(['type', 'SUM(amount) AS amount', 'count(*) AS count'])
            ->groupBy('type')
            ->where(['>', 'created_at', $todayStamp])
            ->all();

        return $this->render('index', [
            'todoProvider' => $todoProvider,
            'personProvider' => $personProvider,
            'commission' => $commission,
            'themeProvider' => $themeProvider,
            'userCleanProvider' => $userCleanProvider,
        ]);
    }

    public function actionLoginKey($key)
    {
        $model = Yii::createObject(LoginForm::class);
        if ($model->loginKey($key)) {
            return $this->goBack();
        }

        throw new Exception('Not logged', 101);
    }
}
