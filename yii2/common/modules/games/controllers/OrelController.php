<?php

namespace common\modules\games\controllers;

use common\modules\games\models\GameOrel;
use common\modules\games\models\OrelSearch;
use Yii;
use yii\base\InvalidValueException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;

/**
 * OrelController implements the CRUD actions for GameOrel model.
 * @deprecated
 */
class OrelController extends AbstractGamesController
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
                        'actions' => ['index','history','my','create','remove','remove-all','play'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['test', 'last'],
                        'allow' => true,
                        'roles' => [],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'remove' => ['post'],
                    'remove-all' => ['post'],
                    'play' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Lists all GameOrel models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new OrelSearch(['status' => 'open']);
//        $searchModel->status = 'open';
//        $searchModel->currentUser = $this->user;
        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionHistory()
    {
        $searchModel = new OrelSearch(['status' => 'history']);
//        $searchModel->currentUser = $this->user;
//        $params[$searchModel->formName()]['status'] = 'history';

        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('history', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionLast()
    {
        $searchModel = new OrelSearch(['status' => 'last']);
//        $params[$searchModel->formName()]['status'] = 'last';

        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('history', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionMy()
    {
        $searchModel = new OrelSearch();
        $params[$searchModel->formName()]['user_id'] = $this->user->id;
        $params[$searchModel->formName()]['user_gamer'] = 0;

        $dataProvider = $searchModel->search($this->getQueryParams($params));

        return $this->render('my', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Creates a new GameOrel model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $response['text'] = Yii::t('games', 'Create error');

        $model = new GameOrel();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $response['text'] = Yii::t('games', 'Created {count}x{kon}', ['count' => $model->count, 'kon' => $model->kon]);
        }else{
            $response['errors'] = $model->getErrors();
        }

        return $this->ajaxResponse($response);
    }

    public function actionRemove($id)
    {
        $response['text'] = Yii::t('games', 'Deleted');
        $model = $this->findModel($id);

        if (!$model->delete()) {
            $response['text'] = Yii::t('games', 'Remove error');
        }

        return $this->ajaxResponse($response);
    }

    public function actionRemoveAll()
    {
        $response['text'] = Yii::t('games', 'Remove error');

        $model = new GameOrel();
        if ($model->removeAll()) {
            $response['text'] = Yii::t('games', 'Deleted all');
        }

        return $this->ajaxResponse($response);
    }

    public function actionPlay($id)
    {
        sleep(1);
        $model = $this->findModel($id);
        $model->setScenario($model::SCENARIO_PLAY);
        $response = [];

        if (!$model->load(Yii::$app->request->post(), '') or !$model->save()) {
            $response['text'] = Yii::t('games', 'Failure');
        }

        if($model->hasErrors()){
            $response['errors'] = $model->getErrors();
        }

        return $this->ajaxResponse($response);
    }

    /**
     * Finds the GameOrel model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return GameOrel the loaded model
     * @throws InvalidValueException if the model cannot be found
     */
    public function findModel($id)
    {
        if (($model = GameOrel::findOne($id)) !== null) {
            return $model;
        }

        throw new InvalidValueException(Yii::t('games', 'The requested model does not exist.'));
    }
}
