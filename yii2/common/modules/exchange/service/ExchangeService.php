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
use common\modules\exchange\middleware\CreateMiddleware;
use common\modules\exchange\middleware\ExchangeDataMiddleware;
use common\modules\exchange\models\CreditExchange;
use Exception;
use yii\web\IdentityInterface;

class ExchangeService
{
    public function create(CreateRequest $request): void
    {
        $container = App::container();

        $mdlwr = $container->get(CheckCreditMiddleware::class);
        $mdlwr::$data = $container->get(ExchangeDataMiddleware::class, [$request]);

        $mdlwr
            ->linkWith($container->get(CheckCountMiddleware::class))
            ->linkWith($container->get(CreateMiddleware::class))
            ->linkWith($container->get(UpdatePersonMiddleware::class));
        // availableCount
        // create
        // updatePerson

        if (!$mdlwr->check()) {
            throw new Exception();
        }
    }

    public function confirm($request): void
    {

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
    public function availableCount(IdentityInterface $identity, $model): int
    {
        $count = $model::find()
            ->free()
            ->my($identity)->count();

        $cnt = $identity->person->rating / 10 - $count;
        return round(max($cnt, 0));
    }
}
