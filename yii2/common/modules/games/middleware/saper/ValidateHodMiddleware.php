<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.08.2019
 * Time: 20:54
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameSaper;
use Yii;

class ValidateHodMiddleware extends GameMiddleware
{
    /** @var GameSaper */
    private $model;
    private $error = '';

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->game;

        if (!$this->validateHod()) {
            return $this->stopProcessing($this->error);
        }

        return parent::check();
    }

    /**
     * @return bool
     */
    protected function validateHod(): bool
    {
        // завершена
        if ($this->model->isComplete()) {
            $this->error = Yii::t('games', 'Game complete');
            return false;
        }

        // предыдущий
        if ($this->model->row < $this->model->etap) {
            $this->error = Yii::t('games', 'Previous row');
            return false;
        }

        // следующий
        if ($this->model->row > $this->model->etap) {
            $this->error = Yii::t('games', 'Next row');
            return false;
        }

        return true;
    }
}
