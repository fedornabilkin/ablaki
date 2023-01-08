<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 15.04.2022
 * Time: 23:12
 */

namespace common\modules\exchange\middleware\exchange;

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

        $this->updateData();
        $this->model->delete();

        return parent::check();
    }

    /**
     * @return void
     */
    public function updateData(): void
    {
        if ($this->model->isBuy()) {
            self::$data->changingCredit = $this->model->credit;
        }

        if ($this->model->isSell()) {
            self::$data->changingBalance = $this->model->amount;
        }

        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Delete #' . $this->model->id;

        $this->insertNext(new UpdatePersonMiddleware());
    }
}
