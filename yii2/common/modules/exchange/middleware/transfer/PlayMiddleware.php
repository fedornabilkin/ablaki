<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 13.04.2022
 * Time: 22:22
 */

namespace common\modules\exchange\middleware\transfer;

use common\middleware\AbstractMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\exchange\models\CreditTransfer;

class PlayMiddleware extends AbstractMiddleware
{
    /**
     * @var CreditTransfer
     */
    private $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->getModel();

        $this->model->user_buyer = self::$data->user->user_id;
        $this->model->save();

        // todo take commission
        self::$data->changingCredit = $this->model->amount;
        self::$data->historyType = $this->model->getHistoryType();
        self::$data->historyComment = 'Confirm #' . $this->model->id;

        $this->insertNext(new UpdatePersonMiddleware());

        return parent::check();
    }
}
