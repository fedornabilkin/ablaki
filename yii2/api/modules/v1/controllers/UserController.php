<?php


namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\user\Person;
use common\models\user\User;
use Yii;
use yii\data\ActiveDataProvider;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{


    public function actionWall($login){
        $user = Yii::$app->user;
        $user = User::find()->where(['username' => $login])->one();
        if ($user) {
            return $user;
        }
        throw new NotFoundHttpException(Yii::t('app', 'The requested user does not exist.'));
    }





}