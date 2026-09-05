<?php

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\services\user\DailyRewardService;
use Yii;
use yii\rest\Controller;

class BonusController extends Controller
{
    use AuthTrait;

    public $credit = 1;

    public function actionEveryday()
    {
        $received = (new DailyRewardService(Yii::$app->db))
            ->claimCredit((int)Yii::$app->user->id, (float)$this->credit);

        if (!$received) {
            return ['message' => Yii::t('app', 'The bonus has already been updated today')];
        }
        return ['message' => Yii::t('app', 'Bonus received'), 'credit' => $this->credit];
    }
}
