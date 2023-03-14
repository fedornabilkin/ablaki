<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 16:27
 */

namespace common\modules\games\apiActions;

use common\helpers\App;
use common\middleware\AbstractMiddleware;
use common\modules\games\middleware\GameDataMiddleware;
use Yii;
use yii\base\Model;
use yii\base\UserException;
use yii\rest\Action;

abstract class AbstractCreate extends Action
{
    /** @var Model */
    protected $model;

    abstract public function getMiddleware(): AbstractMiddleware;

    public function loadModel(): bool
    {
        $this->model = new $this->modelClass();

        $this->model->load(Yii::$app->request->post(), '');
        return $this->model->validate();
    }

    public function getDataMiddleware(): GameDataMiddleware
    {
        return new GameDataMiddleware([
            'game' => $this->model,
            'user' => App::user()->identity->person,
        ]);
    }

    public function checkMiddleware(): void
    {
        $middleware = $this->getMiddleware();

        if ($middleware->check()) {
            App::response()->setStatusCode(201);
        } else {
            $errors = $middleware->getErrors();
            throw new UserException(Yii::t('games', $errors[0]));
        }
    }
}
