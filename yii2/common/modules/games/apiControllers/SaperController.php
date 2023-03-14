<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 14:39
 */

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use common\middleware\person\CheckBalanceMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\apiActions\saper\CreateAction;
use common\modules\games\apiActions\saper\DeleteAction;
use common\modules\games\apiActions\saper\RemoveAction;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\CheckNotMyGameMiddleware;
use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\middleware\saper\CheckMyStartedGameMiddleware;
use common\modules\games\middleware\saper\PlayMiddleware;
use common\modules\games\middleware\saper\StartMiddleware;
use common\modules\games\middleware\saper\ValidateHodMiddleware;
use common\modules\games\models\GameSaper;
use Yii;
use yii\base\UserException;
use yii\rest\ActiveController;

class SaperController extends ActiveController
{
    public $modelClass = GameSaper::class;

    /**
     * @return array
     */
    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            Auth::class => [
                'class' => Auth::class,
            ],
        ]);
    }

    /**
     * @return array
     */
    public function actions(): array
    {
        $actions = parent::actions();

        $actions['delete'] = [
            'class' => DeleteAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['remove'] = [
            'class' => RemoveAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        $actions['create'] = [
            'class' => CreateAction::class,
            'modelClass' => $this->modelClass,
            'checkAccess' => [$this, 'checkAccess'],
        ];

        unset($actions['view'], $actions['update']);
        return $actions;
    }

    public function actionStart($id)
    {
        $model = $this->findModel($id);

        $data = new GameDataMiddleware([
            'game' => $model,
            'user' => Yii::$app->user->identity->person,
        ]);

        $middleware = new CheckFreeGameMiddleware();
        $middleware::$data = $data;

        $middleware
            ->linkWith(new CheckNotMyGameMiddleware())
            ->linkWith(new CheckBalanceMiddleware())
            ->linkWith(new StartMiddleware())
            ->linkWith(new UpdatePersonMiddleware());


        if ($middleware->check()) {
            Yii::$app->getResponse()->setStatusCode(201);
        } else {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }

        return true;
    }

    public function actionPlay($id)
    {
        $model = $this->findModel($id);
        $model->setScenario($model::SCENARIO_PLAY);

        if (!$model->load(Yii::$app->request->post(), '') || !$model->validate()) {
            return $model->getErrors();
        }

        $data = new GameDataMiddleware([
            'game' => $model,
            'user' => Yii::$app->user->identity->person,
        ]);

        $middleware = new CheckBalanceMiddleware();
        $middleware::$data = $data;

        $middleware
            ->linkWith(new CheckMyStartedGameMiddleware())
            ->linkWith(new ValidateHodMiddleware())
            ->linkWith(new PlayMiddleware());


        if ($middleware->check()) {
            Yii::$app->getResponse()->setStatusCode(201);
        } else {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }

        return true;
    }

    public function actionDouble($id)
    {
        $model = $this->findModel($id);
    }

    /**
     * Finds the GameOrel model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return GameSaper the loaded model
     * @throws UserException if the model cannot be found
     */
    public function findModel($id)
    {
        $model = call_user_func([$this->modelClass, 'findOne'], $id);
        if (!$model) {
            throw new UserException(Yii::t('games', 'The requested model does not exist.'));
        }
        return $model;
    }
}
