<?php

namespace api\modules\v1\controllers;

use api\components\ApiList;
use api\modules\v1\models\Person;
use api\modules\v1\models\User;
use api\modules\v1\traites\AuthTrait;
use common\helpers\App;
use common\services\user\PresenceService;
use Yii;
use yii\rest\Controller;
use yii\web\NotFoundHttpException;

class UserController extends Controller
{
    use AuthTrait;

    public function authExceptAction(): array
    {
        return ['wall', 'last', 'index', 'online', 'online-count'];
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
        return $this->actionIndex();
    }

    public function actionIndex()
    {
        return ApiList::provider(User::find()->with(['person']), ['username'], ['id', 'username', 'created_at']);
    }

    public function actionReferrals()
    {
        $ids = Person::find()->select('user_id')->where(['refovod' => (int)App::user()->id]);
        return ApiList::provider(User::find()->with(['person'])->where(['id' => $ids]), ['username'], ['id', 'username', 'created_at']);
    }

    public function actionOnline()
    {
        $query = User::find()->with(['person'])->where(['id' => PresenceService::onlineIds()]);
        // The online modal shows the complete active list in a scrollable region.
        if (Yii::$app->request->get('all') === '1') {
            return $query->orderBy(['id' => SORT_DESC])->all();
        }
        return ApiList::provider($query,
            ['username'], ['id', 'username', 'created_at']);
    }

    public function actionOnlineCount(): array
    {
        return [
            'count' => (int)User::find()->where(['id' => PresenceService::onlineIds()])->count(),
            'windowSeconds' => PresenceService::WINDOW_SECONDS,
        ];
    }

    public function actionHeartbeat(): array
    {
        // Auth records activity before this action and throttles writes to once a minute.
        return $this->actionOnlineCount();
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
