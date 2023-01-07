<?php

namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\history\HistoryBalance;
use Yii;
use yii\rest\Controller;

class BonusController extends Controller
{
    public $credit = 1;

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            Auth::class => [
                'class' => Auth::class,
            ],
        ]);
    }

    public function actionEveryday()
    {
        $beginOfDay = strtotime("midnight", time());
        $user = Yii::$app->user->identity;

        $todayBalance = HistoryBalance::find()
            ->where(['user_id' => $user->id])
            ->andWhere(['>=', 'created_at', $beginOfDay])
            ->andWhere(['=', 'type', 'everyday'])
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
