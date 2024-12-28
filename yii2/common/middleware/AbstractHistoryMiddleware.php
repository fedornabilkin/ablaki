<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 14:14
 */

namespace common\middleware;

use yii\db\ActiveRecord;

/**
 * Class AbstractHistoryMiddleware
 * @package common\middleware
 */
abstract class AbstractHistoryMiddleware extends AbstractMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $values = $this->getHistoryValues();
        if ($values) {
            $model = $this->getHistoryModel();
            $model->setAttributes($values);

            $model->save();
        }

        return parent::check();
    }

    abstract public function getHistoryModel(): ActiveRecord;

    abstract public function getHistoryValues(): array;
}
