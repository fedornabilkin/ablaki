<?php
namespace common\modules\world\apiControllers;

use common\modules\world\modules\economy\models\Account;
use common\modules\world\support\GameError;
use Yii;

class AccountApiController extends CoreController
{
    public function actionView($id): array { return Account::state($this->id($id), (int)Yii::$app->user->id); }
    public function actionCreate($id): array
    {
        $node = $this->id($id); $amount = Yii::$app->request->post('amount');
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,4})?$/D', $amount)) throw new GameError('INVALID_AMOUNT', 'Сумма должна быть строкой с точностью до четырёх знаков.', 422);
        return $this->command('world.budget.fund', ['node_id' => $node, 'amount' => $amount], function ($operation) use ($node, $amount) {
            return Account::fund($node, (int)Yii::$app->user->id, $amount, $operation);
        });
    }
}
