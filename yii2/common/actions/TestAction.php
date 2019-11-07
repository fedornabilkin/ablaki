<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 02.08.2018
 * Time: 22:10
 */

namespace common\actions;


use common\controllers\FrontendController;
use yii\base\Action;

class TestAction extends Action
{

    public function run($id)
    {
        /** @var FrontendController $ctrl */
        $ctrl = $this->controller;

        $param['model'] = $ctrl->findModel($id);
        $param['text'] = 'Test';
        return $ctrl->ajaxResponse($param);
    }
}