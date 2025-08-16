<?php

namespace api\modules\v1\controllers;

use api\modules\v1\models\User;
use api\modules\v1\traites\AuthTrait;
use common\helpers\App;
use yii\rest\Controller;

class UserController extends Controller
{
    use AuthTrait;

    public function authExceptAction(): array
    {
        return ['wall', 'last'];
    }

    public function actionWall($login)
    {
        return User::find()
            ->where(['username' => $login])
            ->with(['person'])
            ->one();
    }

    public function actionLast()
    {
        return User::find()
            ->with(['person'])
            ->orderBy(['id' => SORT_DESC])
            ->limit(20)
            ->all();
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
