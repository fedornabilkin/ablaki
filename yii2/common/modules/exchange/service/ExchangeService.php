<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 10.01.2022
 * Time: 23:22
 */

namespace common\modules\exchange\service;

use common\helpers\App;
use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\requests\CreateRequest;
use common\modules\exchange\middleware\CheckCountMiddleware;
use common\modules\exchange\middleware\CheckFreeMiddleware;
use common\modules\exchange\middleware\CreateMiddleware;
use common\modules\exchange\middleware\ExchangeDataMiddleware;
use common\modules\exchange\middleware\PlayMiddleware;
use common\modules\exchange\middleware\SwitchCreatorMiddleware;
use common\modules\exchange\models\CreditExchange;
use Exception;
use Yii;
use yii\web\IdentityInterface;

class ExchangeService
{
    public function create(CreateRequest $request): void
    {
        $container = App::container();

        $mdlwr = $container->get(CheckCreditMiddleware::class);
        $mdlwr::$data = $container->get(ExchangeDataMiddleware::class, [$request]);
        $mdlwr::$data->user = App::user()->identity->person;

        $mdlwr
            ->linkWith($container->get(CheckCountMiddleware::class))
            ->linkWith($container->get(CreateMiddleware::class))
            ->linkWith($container->get(UpdatePersonMiddleware::class));
        // availableCount
        // create

        if (!$mdlwr->check()) {
            throw new Exception();
        }
    }

    public function confirm(CreditExchange $model): void
    {
        $container = App::container();

        // check
        $mdlwr = $container->get(CheckFreeMiddleware::class);
        /** @var ExchangeDataMiddleware data */
        $mdlwr::$data = $container->get(ExchangeDataMiddleware::class);
        $mdlwr::$data->user = App::user()->identity->person;
        $mdlwr::$data->model = $model;

        $mdlwr
            ->linkWith($container->get(PlayMiddleware::class))
            ->linkWith($container->get(SwitchCreatorMiddleware::class));
        // update user client
        // update user
        // update model (confirm)
        // history comission

        if (!$mdlwr->check()) {
            throw new Exception(
                Yii::t('exchange', 'Error confirm')
            );
        }
    }

    public function remove($request): void
    {

    }

    /**
     * @param CreditExchange $model
     * @return float
     */
    public function pricePerThousand(CreditExchange $model): float
    {
        return 1000 * $model->amount / $model->credit;
    }

    /**
     * @param IdentityInterface $identity
     * @param CreditExchange $model
     * @return int
     */
    public function availableCount(IdentityInterface $identity, CreditExchange $model): int
    {
        $count = $model::find()
            ->free()
            ->my($identity)->count();

        $cnt = $identity->person->rating / 10 - $count;
        return round(max($cnt, 0));
    }
}
