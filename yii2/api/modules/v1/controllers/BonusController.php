<?php

namespace api\modules\v1\controllers;

use api\modules\v1\models\history\HistoryBalance;
use api\modules\v1\traites\AuthTrait;
use Yii;
use yii\rest\ActiveController;

class BonusController extends ActiveController
{
    use AuthTrait;

    public $modelClass = HistoryBalance::class;

    public $credit = 1;

    public function actions()
    {
        $actions = parent::actions();

        unset($actions['view'], $actions['update'], $actions['delete'], $actions['create'], $actions['index']);

        return $actions;
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
