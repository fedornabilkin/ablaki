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
use common\modules\games\middleware\GamerCheckCreditMiddleware;
use common\modules\games\middleware\CheckFreeGameMiddleware;
use common\modules\games\middleware\orel\PlayMiddleware;
use common\modules\games\middleware\orel\SwitchCreatorMiddleware;
use common\modules\games\models\GameOrel;
use Yii;
use yii\base\UserException;
use yii\rest\ActiveController;

class DuelController extends ActiveController
{

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

    }

    /**
     * @param $id
     * @return array|bool
     * @throws UserException
     * @throws \yii\db\Exception
     */
    public function actionPlay($id)
    {

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

    }
}
