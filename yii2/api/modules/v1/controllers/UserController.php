<?php

namespace api\modules\v1\controllers;

use api\filters\Auth;
use api\modules\v1\models\User;
use common\helpers\App;
use yii\rest\Controller;

class UserController extends Controller
{

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            Auth::class => [
                'class' => Auth::class,
                'except' => ['wall']
            ],
        ]);
    }

    public function actionWall($login)
    {
        return User::find()
            ->where(['username' => $login])
            ->with(['person'])
            ->one();
    }

    /**
     * Для ограничения отображения полей получаем данные пользователя через модель для v1
     * @return User|null
     */
    public function actionProfile()
    {
        return User::findOne(App::user()->identity->getId());
    }

    public function actionData()
    {
        return [
            'user' => 'admin',
            'fieldName' => 'fieldValue',
        ];
    }
}
