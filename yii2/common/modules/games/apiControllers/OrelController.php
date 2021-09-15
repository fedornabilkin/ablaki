<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 20:27
 */

namespace common\modules\games\apiControllers;

use api\filters\Auth;
use common\middleware\DataMiddleware;
use common\middleware\HistoryCommissionMiddleware;
use common\modules\games\apiActions\orel\CreateAction;
use common\modules\games\apiActions\orel\DeleteAction;
use common\modules\games\apiActions\orel\RemoveAction;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\GamerCheckCreditMiddleware;
use common\modules\games\middleware\orel\PlayMiddleware;
use common\modules\games\middleware\orel\SwitchCreatorMiddleware;
use common\modules\games\models\GameOrel;
use Yii;
use yii\base\UserException;
use yii\db\Exception;
use yii\rest\ActiveController;

class OrelController extends ActiveController
{
    public $modelClass = GameOrel::class;

    public function behaviors()
    {
        $parent = parent::behaviors();
        $arr = [
            'authenticator' => [
                'class' => Auth::class,
            ],
        ];

        return array_merge($parent, $arr);
    }

    public function actions()
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

    /**
     * @param $id
     * @return array|bool
     * @throws UserException
     * @throws Exception
     */
    public function actionPlay($id)
    {
        $model = $this->findModel($id);
        $model->setScenario($model::SCENARIO_PLAY);

        if (!$model->load(Yii::$app->request->post(), '') || !$model->validate()) {
            return $model->getErrors();
        }

        $data = new DataMiddleware([
            'game' => $model,
            'user' => Yii::$app->user->identity->person,
        ]);

        $middleware = new GamerCheckCreditMiddleware();
        $middleware::$data = $data;

        $middleware
            ->linkWith(new CheckFreeGameMiddleware())
            ->linkWith(new PlayMiddleware())
            ->linkWith(new SwitchCreatorMiddleware())
            ->linkWith(new HistoryCommissionMiddleware());

        if (!$middleware->check()) {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }

        return true;
    }

    /**
     * Finds the GameOrel model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return GameOrel the loaded model
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
