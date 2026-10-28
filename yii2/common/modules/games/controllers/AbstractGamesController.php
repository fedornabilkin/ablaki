<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 15:57
 */

namespace common\modules\games\controllers;


use common\actions\TestAction;
use common\controllers\FrontendController;

/**
 * @deprecated
 */
class AbstractGamesController extends FrontendController
{
    protected $model;

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) return false;
        // These deprecated HTML handlers write game rows without reserving/refunding stakes.
        // They must not bypass the current transactional API once the exact wallet is active.
        if (\common\modules\world\modules\economy\models\domain\WalletSchema::ready(\Yii::$app->db)
            && in_array($action->id, ['create', 'remove', 'remove-all', 'start', 'play', 'double'], true)) {
            throw new \yii\web\GoneHttpException('Этот старый игровой маршрут закрыт. Используйте текущую страницу игры.');
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return array_merge(parent::actions(), [
            'test' => [
                'class' => TestAction::class,
            ],
        ]);
    }

    public function ajaxResponse($params = [])
    {
        return parent::ajaxResponse($params);
    }

    /**
     * @param array $params
     * @return array
     */
    protected function getQueryParams($params = [])
    {
        return parent::getQueryParams($params);
    }
}
