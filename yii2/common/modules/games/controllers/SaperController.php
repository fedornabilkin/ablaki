<?php

namespace common\modules\games\controllers;

use common\modules\games\models\GameSaper;
use common\modules\games\models\SaperSearch;
use Yii;
use yii\base\InvalidValueException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;

class SaperController extends AbstractGamesController
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
//                'only' => ['logout', 'signup'],
                'rules' => [
                    [
                        'actions' => ['index','history','my','create','remove','remove-all','start','play','double'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['test', 'last'],
                        'allow' => true,
                        'roles' => ['?','@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'create' => ['post'],
                    'remove' => ['post'],
                    'remove-all' => ['post'],
                    'start' => ['post'],
                    'play' => ['post'],
                    'double' => ['post'],
                ],
            ],
        ];
    }


    public function actionIndex()
    {
        $searchModel = new SaperSearch(['status' => 'open']);
        $searchModel->currentUser = $this->user;

        $dataProvider = $searchModel->search($this->getQueryParams());

        $unfinished = '';

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionHistory()
    {
        $searchModel = new SaperSearch(['status' => 'history']);
        $searchModel->currentUser = $this->user;

        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('history', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionLast()
    {
        $searchModel = new SaperSearch(['status' => 'last']);
        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('history', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionMy()
    {
        $searchModel = new SaperSearch(['user_id' => $this->user->id, 'user_gamer' => 0]);
        $dataProvider = $searchModel->search($this->getQueryParams());

        return $this->render('my', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionView()
    {
        return $this->ajaxResponse();
    }

    public function actionCreate()
    {
        $response = Yii::t('games', 'Create error');

        $model = new GameSaper();
        $model->setScenario($model::SCENARIO_CREATE);
        $model->user_id = $this->getUser()->id;
        $model->user_gamer = 0;

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $response = Yii::t('games', 'Created');
        }


        $this->ajaxParams['text'] = $response;
        return $this->ajaxResponse();
    }

    public function actionRemove($id)
    {
        $response = Yii::t('games', 'Deleted');
        $res = GameSaper::deleteAll([
            'id' => $id,
            'user_id' => $this->getUser()->id,
            'etap' => GameSaper::GAME_SAPER_ETAP_NEW
        ]);
        if (!$res) {
            $response = Yii::t('games', 'Remove error');
        }

        $this->ajaxParams['text'] = $response;
        return $this->ajaxResponse();
    }

    public function actionRemoveAll()
    {
        $response = Yii::t('games', 'Deleted all');
        $res = GameSaper::deleteAll(['user_id' => $this->getUser()->id, 'etap' => GameSaper::GAME_SAPER_ETAP_NEW]);
        if (!$res) {
            $response = Yii::t('games', 'Remove error');
        }

        $this->ajaxParams['text'] = $response;
        return $this->ajaxResponse();
    }

    public function actionStart($id)
    {
        $model = GameSaper::findOne($id);
        $model->setScenario($model::SCENARIO_START);

        $data['user_gamer'] = $this->getUser()->id;
//        $data['user_gamer'] = 2;
        $data['time_start_at'] = time();

        $response = Yii::t('games', 'Unable to start game');

        if ($model->load($data,'') && $model->save()) {
            $response = Yii::t('games', 'Started');
        }
        elseif($model->hasErrors()){
            $response = $model->getFirstError('start');
        }

        $this->ajaxParams['model'] = $model;
        $this->ajaxParams['text'] = $response;
        return $this->ajaxResponse();
    }

    public function actionPlay($id)
    {
        $model = GameSaper::findOne($id);
        $model->setScenario($model::SCENARIO_PLAY);
        $model->user_gamer = $this->getUser()->id;

        $response = Yii::t('games', 'Failure');

        if ($model->load(Yii::$app->request->post(), '') && $model->save()) {
            $response = Yii::t('games', 'Success');
        }
        elseif($model->hasErrors()){
            $response = $model->getFirstError('play');
        }

        $this->ajaxParams['hod'] = rand(1,2) == 1 ? true : false;
        $this->ajaxParams['model'] = $model;
        $this->ajaxParams['errors'] = $model->getErrors();
        $this->ajaxParams['text'] = $response;
        return $this->ajaxResponse();
    }

    public function actionDouble()
    {
        return $this->ajaxResponse();
    }

    /**
     * Finds the GameSaper model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return GameSaper the loaded model
     * @throws InvalidValueException if the model cannot be found
     */
    public function findModel($id)
    {
        if (($model = GameSaper::findOne($id)) !== null) {
            return $model;
        }

        throw new InvalidValueException(Yii::t('games', 'The requested model does not exist.'));
    }
}
