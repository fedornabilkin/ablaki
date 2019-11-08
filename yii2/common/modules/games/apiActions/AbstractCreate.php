<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 16:27
 */

namespace common\modules\games\apiActions;

use common\middleware\DataMiddleware;
use common\modules\games\middleware\GameMiddleware;
use Yii;
use yii\base\Model;
use yii\base\UserException;
use yii\rest\Action;

abstract class AbstractCreate extends Action
{
    /** @var Model */
    protected $model;

    abstract public function getMiddleware(): GameMiddleware;

    public function loadModel()
    {
        $this->model = new $this->modelClass();

        $this->model->load(Yii::$app->request->post(), '');
        return $this->model->validate();
    }

    public function getDataMiddleware()
    {
        $data = new DataMiddleware([
            'game' => $this->model,
            'user' => \Yii::$app->user->identity->person,
        ]);

        return $data;
    }

    public function checkMiddleware()
    {
        $middleware = $this->getMiddleware();

        if ($middleware->check()) {
            Yii::$app->getResponse()->setStatusCode(201);
        } else {
            $errors = $middleware->getErrors();
            throw new UserException(\Yii::t('games', $errors[0]));
        }
    }
}
