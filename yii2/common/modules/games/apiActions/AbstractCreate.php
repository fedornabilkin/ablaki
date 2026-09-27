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
use yii\base\InvalidArgumentException;
use yii\base\Model;
use yii\base\UserException;
use yii\rest\Action;
use yii\web\BadRequestHttpException;

abstract class AbstractCreate extends Action
{
    /** @var Model */
    protected $model;

    abstract public function getMiddleware(): AbstractMiddleware;

    public function loadModel(): bool
    {
        $this->model = new $this->modelClass();

        $this->model->load(Yii::$app->request->post(), '');

        $validate = $this->model->validate();
        if (!$validate) {
            $errors = $this->model->getFirstErrors();
            throw new BadRequestHttpException(reset($errors));
        }
        return $validate;
    }

    public function getDataMiddleware(): GameDataMiddleware
    {
        return new GameDataMiddleware([
            'game' => $this->model,
            'user' => App::user()->identity->person,
        ]);
    }

    public function checkMiddleware(): bool
    {
        Yii::$app->db->transaction(function (): void {
            $db = Yii::$app->db;
            \common\modules\economy\service\WalletMaintenance::writable($db);
            $person = App::user()->identity->person;
            $row = (new \common\services\user\CreditLedger($db))->lock('persone', ['user_id' => (int)$person->user_id]);
            if (!$row) throw new \RuntimeException('Player unavailable.');
            $person::populateRecord($person, $row);
            if ($this->model::tableName() !== 'game_saper' && \common\modules\economy\service\WalletSchema::ready($db)) {
                \common\modules\economy\service\LegacyCreditPolicy::game($this->model->kon);
            }
            $middleware = $this->getMiddleware();
            if (!$middleware->check()) throw new BadRequestHttpException(Yii::t('games', $middleware->getErrors()[0] ?? 'Error create game'));
        });
        App::response()->setStatusCode(201);
        return false; // Preserve the existing successful JSON result (formerly bool-cast from '').
    }
}
