<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 10.01.2022
 * Time: 23:22
 */

namespace common\modules\exchange\service;

use common\helpers\App;
use common\middleware\AbstractMiddleware;
use common\middleware\person\CheckBalanceMiddleware;
use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use common\modules\exchange\exception\CountException;
use common\modules\exchange\middleware\CheckFreeMiddleware;
use common\modules\exchange\middleware\CheckMyMiddleware;
use common\modules\exchange\middleware\exchange\CheckCountMiddleware;
use common\modules\exchange\middleware\exchange\CreateMiddleware;
use common\modules\exchange\middleware\exchange\DeleteMiddleware;
use common\modules\exchange\middleware\exchange\PlayMiddleware;
use common\modules\exchange\middleware\exchange\RemoveAllMiddleware;
use common\modules\exchange\middleware\exchange\SwitchCreatorMiddleware;
use common\modules\exchange\middleware\ExchangeDataMiddleware;
use Exception;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\IdentityInterface;

class ExchangeService
{
    /**
     * @param CreditExchange $model
     * @return void
     * @throws CountException
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @throws \yii\db\Exception
     */
    public function create(CreditExchange $model): void
    {
        $container = App::container();

        $middle = $container->get($this->getChecker($model));
        $middle
            ->linkWith($container->get(CheckCountMiddleware::class))
            ->linkWith($container->get(CreateMiddleware::class))
            ->linkWith($container->get(UpdatePersonMiddleware::class));

        $middle::$data = $container->get(ExchangeDataMiddleware::class, [App::user()->identity->person, $model]);

        $availableCnt = $this->availableCount(App::user()->identity, $model);
        $middle::$data->setAvailableCount($availableCnt);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('exchange', 'Error create')
            );
        }
    }

    /**
     * @param CreditExchange $model
     * @return void
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws NotInstantiableException
     */
    public function confirm(CreditExchange $model): void
    {
        $container = App::container();

        $middle = $container->get(CheckFreeMiddleware::class);
        $middle
            ->linkWith($container->get($this->getChecker($model, false)))
            ->linkWith($container->get(PlayMiddleware::class))
            ->linkWith($container->get(SwitchCreatorMiddleware::class));

        $middle::$data = $container->get(ExchangeDataMiddleware::class, [App::user()->identity->person, $model]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('exchange', 'Error confirm')
            );
        }
    }

    public function delete(CreditExchange $model): void
    {
        $container = App::container();

        $middle = $container->get(CheckFreeMiddleware::class);
        $middle
            ->linkWith($container->get(CheckMyMiddleware::class))
            ->linkWith($container->get(DeleteMiddleware::class));

        $middle::$data = $container->get(ExchangeDataMiddleware::class, [App::user()->identity->person, $model]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('exchange', 'Error delete')
            );
        }
    }

    public function remove(): void
    {
        $container = App::container();

        $middle = $container->get(RemoveAllMiddleware::class);
        $middle->linkWith($container->get(UpdatePersonMiddleware::class));

        $middle::$data = $container->get(ExchangeDataMiddleware::class, [App::user()->identity->person, new CreditExchange()]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('exchange', 'Error remove')
            );
        }
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

    /**
     * @param CreditExchange $model
     * @param bool $create
     * @return AbstractMiddleware
     *
     * if confirm and type == sell, check credit
     * if create and type == sell, check balance
     */
    private function getChecker(CreditExchange $model, bool $create = true): string
    {
        if ($create) {
            return ($model->isBuy()) ? CheckCreditMiddleware::class : CheckBalanceMiddleware::class;
        }
        return ($model->isSell()) ? CheckCreditMiddleware::class : CheckBalanceMiddleware::class;
    }
}
