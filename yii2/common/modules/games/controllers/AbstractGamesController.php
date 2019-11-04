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

class AbstractGamesController extends FrontendController
{
    protected $model;

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