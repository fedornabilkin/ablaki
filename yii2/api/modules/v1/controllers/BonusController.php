<?php


namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\HistoryBalance;
use common\models\user\Person;
use Yii;
use yii\rest\Controller;

class BonusController extends Controller
{
    /** @var int $credit */
    public $credit = 1;

    public function behaviors(): array
    {
        $parent = parent::behaviors();
        $arr = [
            'authenticator' => [
                'class' => Auth::class,
            ],
        ];

        return array_merge($parent, $arr);
    }

    public function actionEveryday()
    {
        $beginOfDay = strtotime("midnight", time());
        $user = Yii::$app->user->identity;

        $todayBalance = HistoryBalance::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['>=', 'created_at', $beginOfDay])
            ->one();

        if ($todayBalance) {
            return ['message' => 'Бонус уже был обновлен сегодня'];
        }

        $person = $user->person;

        $historyBalance = new HistoryBalance();
        $historyBalance->user_id = $user->id;
        $historyBalance->balance = $person->balance;
        $historyBalance->credit = $person->credit;
        $historyBalance->balance_up = 0;
        $historyBalance->credit_up = $this->credit;
        $historyBalance->type = 'everyday';
        $historyBalance->comment = 'everyday';
        $historyBalance->save();

        return $person->updateCounters(['credit' => $this->credit]);


    }



}
