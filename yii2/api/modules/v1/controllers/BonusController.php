<?php

namespace api\modules\v1\controllers;

use api\modules\v1\traites\AuthTrait;
use common\services\user\DailyRewardService;
use common\services\user\PresenceService;
use common\services\user\PrizeFundService;
use api\components\ApiList;
use api\modules\v1\models\DailyBonus;
use Yii;
use yii\rest\Controller;

class BonusController extends Controller
{
    use AuthTrait;

    public $credit = 1;

    public function authExceptAction(): array
    {
        return ['recipients', 'fund'];
    }

    public function actionFund(): array
    {
        return (new PrizeFundService(Yii::$app->db))->summary();
    }

    public function actionMyFund(): array
    {
        return (new PrizeFundService(Yii::$app->db))->summary((int)Yii::$app->user->id);
    }

    public function actionRecipients()
    {
        list($start, $end) = PresenceService::dayBounds();
        $query = DailyBonus::find()->with(['user.person'])->where(['type' => 'everyday'])
            ->andWhere(['>', 'credit_up', 0])->andWhere(['>=', 'created_at', $start])
            ->andWhere(['<', 'created_at', $end])->andWhere(['<=', 'created_at', time()]);
        return ApiList::provider($query, [], ['id', 'created_at'], static function ($query, $search) {
            $query->andWhere(ApiList::relatedUserCondition(['user_id'], $search));
        });
    }

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
