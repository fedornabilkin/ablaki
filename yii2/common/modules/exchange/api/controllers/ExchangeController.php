<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 09.01.2022
 * Time: 18:13
 */

namespace common\modules\exchange\api\controllers;

use common\helpers\App;
use common\modules\exchange\api\actions\exchange\{CreateAction, DeleteAction, RemoveAction, UpdateAction};
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\service\ExchangeService;
use Yii;
use yii\base\DynamicModel;
use yii\data\ActiveDataFilter;
use yii\data\ActiveDataProvider;
use yii\rest\ActiveController;
use yii\web\ForbiddenHttpException;

/**
 * При создании заявки
 * завка на продажу кредитов, (другие будут покупать за кг)
 *  в БД пишем 'type' == 'buy'
 *  user_id - change credit (down)
 *
 * заявка на покупку кредитов, (другие будут отдавать кредиты)
 *  в БД пишем 'type' == 'sell'
 *  change balance (down)
 *
 *
 * При удалении заявки
 *  'type' == 'buy' - user_id - change credit (up)
 *  'type' == 'sell' - user_id - change balance (up)
 *
 *
 * При подтверждении заявки
 * Юзер продает кредиты за килограммы, 'type' == 'sell'.
 *  user_buyer - change balance (up) credit (down)
 *  забрать комиссию в кредитах
 *  user_id - change credit (up)
 *
 * Юзер покупает кредиты за килограммы 'type' == 'buy'.
 *  user_buyer - change balance (down) credit (up)
 *  зачислить комиссию в кг
 *  user_id - change balance (up)
 */
class ExchangeController extends ActiveController
{
    /** @var CreditExchange */
    public $modelClass = CreditExchange::class;

    public $createScenario = CreditExchange::SCENARIO_CREATE;

    public function actions(): array
    {
        $actions = parent::actions();

        $actions['create']['class'] = CreateAction::class;
        $actions['update']['class'] = UpdateAction::class;
        $actions['delete']['class'] = DeleteAction::class;
        $actions['remove']['class'] = RemoveAction::class;

        $actions['index']['dataFilter'] = $this->getFilter();

        $actions['history'] = $actions['index'];
        $actions['my'] = $actions['index'];

        $actions['index']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            $sortPrice = $filter['type'] === $this->modelClass::EX_TYPE_SELL ? SORT_DESC : SORT_ASC;
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->select('*, (1000 * amount / credit) AS price')
                    ->orderBy(['price' => $sortPrice])
                    ->with(['user', 'userBuyer'])
                    ->list(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $actions['history']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->orderBy(['updated_at' => SORT_DESC])
                    ->with(['user', 'userBuyer'])
                    ->listHistory(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        $actions['my']['prepareDataProvider'] = function ($action, $filter) {
            $filter = $filter ?? [];
            return new ActiveDataProvider([
                'query' => $this->modelClass::find()
                    ->select('*, (1000 * amount / credit) AS price')
                    ->orderBy(['price' => SORT_ASC])
                    ->with(['user', 'userBuyer'])
                    ->listMy(App::user()->identity)
                    ->andFilterWhere($filter),
            ]);
        };

        unset($actions['view']);

        return $actions;
    }

    public function actionAvailableCount(): int
    {
        return (new ExchangeService())
            ->availableCount(App::user()->identity, (new $this->modelClass));
    }

    public function actionStatistic()
    {
        return [];
    }

    public function checkAccess($action, $model = null, $params = []): void
    {
        parent::checkAccess($action, $model, $params);

        if (
            ($action === 'delete' && $model->user_id !== App::user()->id)
            || ($action === 'update' && $model->user_id === App::user()->id)
        ) {
            throw new ForbiddenHttpException(
                Yii::t('exchange', sprintf('The %s action is not available.', $action)),
            );
        }
    }

    /**
     * @return array
     */
    private function getFilter(): array
    {
        return [
            'class' => ActiveDataFilter::class,
            'searchModel' => function () {
                return (new DynamicModel(['amount' => null, 'credit' => null, 'type' => null]))
                    ->addRule(['credit', 'amount'], 'number')
                    ->addRule('type', 'string');
            },
        ];
    }
}
