<?php


namespace api\modules\v1\controllers;

use api\filters\Auth;
use common\models\HistoryBalance;
use common\models\user\Person;
use Yii;
use yii\rest\Controller;

class BonusController extends Controller
{


    public $credit = 1;

    public function behaviors()
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
        $person = Person::findOne(Yii::$app->user->identity->id);
        $historyBalance = new HistoryBalance();
        $historyBalance->user_id = Yii::$app->user->identity->id;
        $historyBalance->balance = $person->balance;
        $historyBalance->credit = $person->credit;
        $historyBalance->balance_up = 0;
        $historyBalance->credit_up = $this->credit;
        $historyBalance->type = 'everyday';
        $historyBalance->comment = 'everyday';
        $historyBalance->save();
        $upd = $person->updateCounters(['credit' => $this->credit]);
        return $upd;

    }



}