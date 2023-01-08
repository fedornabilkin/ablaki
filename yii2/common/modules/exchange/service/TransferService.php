<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 08.01.2023
 * Time: 16:45
 */

namespace common\modules\exchange\service;

use common\helpers\App;
use common\middleware\person\CheckCreditMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\middleware\CheckFreeMiddleware;
use common\modules\exchange\middleware\CheckMyMiddleware;
use common\modules\exchange\middleware\transfer\CreateMiddleware;
use common\modules\exchange\middleware\transfer\DeleteMiddleware;
use common\modules\exchange\middleware\transfer\PlayMiddleware;
use common\modules\exchange\middleware\TransferDataMiddleware;
use common\modules\exchange\models\CreditTransfer;
use Exception;
use Yii;

class TransferService
{
    public function create(CreditTransfer $model)
    {
        $container = App::container();

        $middle = $container->get(CheckCreditMiddleware::class);
        $middle
            ->linkWith($container->get(CreateMiddleware::class))
            ->linkWith($container->get(UpdatePersonMiddleware::class));

        $middle::$data = $container->get(TransferDataMiddleware::class, [App::user()->identity->person, $model]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('transfer', 'Error create')
            );
        }
    }

    public function confirm(CreditTransfer $model)
    {
        $container = App::container();

        $middle = $container->get(CheckFreeMiddleware::class);
        $middle
            ->linkWith($container->get(PlayMiddleware::class));

        $middle::$data = $container->get(TransferDataMiddleware::class, [App::user()->identity->person, $model]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('transfer', 'Error confirm')
            );
        }
    }

    public function delete(CreditTransfer $model)
    {
        $container = App::container();

        $middle = $container->get(CheckFreeMiddleware::class);
        $middle
            ->linkWith($container->get(CheckMyMiddleware::class))
            ->linkWith($container->get(DeleteMiddleware::class));

        $middle::$data = $container->get(TransferDataMiddleware::class, [App::user()->identity->person, $model]);

        // todo transaction
        if (!$middle->check()) {
            throw new Exception(
                Yii::t('transfer', 'Error delete')
            );
        }
    }
}
