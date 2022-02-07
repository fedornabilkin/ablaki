<?php

namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\helpers\App;
use common\models\user\User;
use yii\rest\Controller;

class UserController extends Controller
{

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'authenticator' => [
                'class' => Auth::class,
                'except' => ['wall']
            ],
        ]);
    }

    public function actionWall($login)
    {
        $user = User::find()
            ->where(['username' => $login])
            ->with(['person'])
            ->one();

//        if (!$user) {
//            throw new NotFoundHttpException(Yii::t('app', 'The requested user does not exist.'));
//        }

        return $user;
    }

    public function actionProfile()
    {
        return App::user()->identity;
    }

    public function actionData()
    {
        return [
            'user' => 'admin',
        ];
    }
}
