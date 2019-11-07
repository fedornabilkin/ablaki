<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 21:18
 */

namespace common\modules\games\apiActions;

use common\middleware\DataMiddleware;
use common\modules\games\middleware\GameMiddleware;
use Yii;
use yii\base\UserException;
use yii\rest\Action;

abstract class AbstractRemove extends Action
{
    abstract public function getMiddleware(): GameMiddleware;

    public function getDataMiddleware()
    {
        $data = new DataMiddleware([
            'user' => Yii::$app->user->identity->person,
        ]);

        return $data;
    }

    public function checkMiddleware()
    {
        $middleware = $this->getMiddleware();

        if ($middleware->check()) {
            Yii::$app->getResponse()->setStatusCode(204);
        } else {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }
    }
}
