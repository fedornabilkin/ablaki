<?php

namespace common\services\game;

use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\models\user\Person;
use common\models\user\User;
use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\middleware\orel\CreateMiddleware;
use common\modules\games\models\GameOrel;
use Yii;
use yii\console\Exception;

/**
 * получаем параметры создания (сколько штук, какая ставка)
 * получаем айди пользователя и смотрим план создания
 * проверяем количество игр с этим коном
 * пробуем создать недостающее количество игр с таким коном
 * если баланса не хватило, то переходим к дургому пользователю
 */
class GameCreateService
{
    public function execute(): void
    {
        foreach ($this->plan() as $userId => $userPlan) {
            foreach ($userPlan as $kon => $countPlanned) {

                // сколько игр уже создано с этой ставкой для пользователя
                $alreadyCreated = $this->searchGame($userId, $kon);

                // сколько еще нужно создать
                $count = $countPlanned - $alreadyCreated;

                if ($count > 0) {
                    $this->createGame($userId, $kon, $count);
                }

            }
        }
    }

    /**
     * 1=>20 - kon=>count
     * @return array[]
     * @throws \yii\base\InvalidConfigException
     */
    protected function plan(): array
    {
        $user = User::find()->where(['username' => 'bot'])->one();
        return [
            $user['id'] => [1=>20, 2=>10, 3=>5, 5=>3, 7=>1],
        ];
    }

    protected function person($uid)
    {
        return Person::find()->where(['user_id' => $uid])->one();
    }

    protected function createGame($userId, $kon, $count): void
    {
        $model = new GameOrel();
        $model->load(['kon'=>$kon, 'count'=>$count], '');

        $validate = $model->validate();
        if (!$validate) {
            $errors = $model->getFirstErrors();
            throw new Exception(reset($errors));
        }

        $person = $this->person($userId);
        if (!$person) {
            throw new Exception('No person by ' . $userId);
        }

        $middleware = new CheckCreditMiddleware();
        $middleware::$data = new GameDataMiddleware([
            'game' => $model,
            'user' => $person,
        ]);

        $middleware
            ->linkWith(new CreateMiddleware())
            ->linkWith(new UpdatePersonMiddleware());

        try {
            $middleware->check();
        } catch (\Exception $e) {
            throw new Exception('Error create game ', 1);
        }
    }

    protected function searchGame($uid, $kon): int
    {
        return GameOrel::find()
            ->where(['user_id' => $uid, 'kon' => $kon])
            ->andWhere(['user_gamer' => 0])
            ->count();
    }
}