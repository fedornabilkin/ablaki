<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 23:12
 */

namespace common\modules\exchange\middleware\transfer;

use common\middleware\AbstractMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use Throwable;
use yii\db\Exception;
use yii\db\StaleObjectException;

class DeleteMiddleware extends AbstractMiddleware
{
    /**
     * @var CreditExchange
     */
    private $model;

    /**
     * @return bool
     * @throws Exception
     * @throws StaleObjectException
     * @throws Throwable
     */
    public function check(): bool
    {
        $this->model = self::$data->getModel();

        self::$data->changingCredit = $this->model->amount;
        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Delete #' . $this->model->id;

        $this->model->delete();

        $this->insertNext(new UpdatePersonMiddleware());

        return parent::check();
    }
}
