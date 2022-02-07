<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 16:49
 */

namespace common\modules\games\apiActions;

use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\middleware\GameMiddleware;
use Yii;
use yii\base\Model;
use yii\base\UserException;
use yii\rest\Action;

abstract class AbstractDelete extends Action
{
    /** @var Model */
    protected $model;

    abstract public function getMiddleware(): GameMiddleware;

    public function loadModel($id)
    {
        $this->model = $this->findModel($id);
    }

    public function getDataMiddleware(): GameDataMiddleware
    {
        return new GameDataMiddleware([
            'game' => $this->model,
            'user' => Yii::$app->user->identity->person,
        ]);
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
