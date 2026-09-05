<?php

namespace api\modules\v1\controllers;

use api\modules\v1\models\Person;
use api\modules\v1\models\User;
use api\modules\v1\traites\AuthTrait;
use common\helpers\App;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{
    use AuthTrait;

    public function authExceptAction(): array
    {
        return ['wall', 'last'];
    }

    /**
     * Редактирование описания на собственной стене.
     * Статистика и остальные данные стены через этот экшен не меняются.
     */
    public function actionWallUpdate()
    {
        $person = Person::findOne(['user_id' => App::user()->identity->getId()]);
        if ($person === null) {
            throw new NotFoundHttpException('Person not found.');
        }

        $person->description = App::request()->getBodyParam('description');
        if (!$person->save(true, ['description'])) {
            App::response()->setStatusCode(422);
            return ['errors' => $person->getErrors()];
        }

        return $person;
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
