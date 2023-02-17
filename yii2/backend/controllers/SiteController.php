<?php
namespace backend\controllers;

use backend\models\user\LoginForm;
use common\models\Todo;
use common\models\user\Person;
use common\modules\forum\models\ForumTheme;
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

        $commission['game_orel'] = (new Query())
            ->from('comission')
            ->select('SUM(amount) AS amount')
            ->groupBy('type')
            ->where(['>', 'created_at', $todayStamp])
            ->andWhere(['type' => 'game_orel'])
            ->one();

        $commission['game_saper'] = (new Query())
            ->from('comission')
            ->select('SUM(amount) AS amount')
            ->groupBy('type')
            ->where(['>', 'created_at', $todayStamp])
            ->andWhere(['type' => 'game_saper'])
            ->one();

        $commission['game_duel'] = (new Query())
            ->from('comission')
            ->select('SUM(amount) AS amount')
            ->groupBy('type')
            ->where(['>', 'created_at', $todayStamp])
            ->andWhere(['type' => 'game_duel'])
            ->one();

        return $this->render('index', [
            'todoProvider' => $todoProvider,
            'personProvider' => $personProvider,
            'commission' => $commission,
            'themeProvider' => $themeProvider,
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
