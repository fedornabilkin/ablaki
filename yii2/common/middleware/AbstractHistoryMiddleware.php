<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 14:14
 */

namespace common\middleware;

use common\middleware\AbstractMiddleware;
use yii\db\ActiveRecord;

/**
 * Class AbstractHistoryMiddleware
 * @package common\middleware
 */
abstract class AbstractHistoryMiddleware extends AbstractMiddleware
{
    protected $values = [];

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->execute();

        return parent::check();
    }

    public function execute()
    {
        $this->setValues();
        if ($this->values) {
            $this->saveHistory();
        }
    }

    public function setValues()
    {
        $this->values = $this->getHistoryValues();
    }

    protected function saveHistory()
    {
        $model = $this->getHistoryModel();
        $model->attributes = $this->values;

        $model->save();
    }

    abstract public function getHistoryModel(): ActiveRecord;
    abstract public function getHistoryValues(): array;
}
