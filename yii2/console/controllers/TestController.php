<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.10.2020
 * Time: 14:33
 */

namespace console\controllers;

use Yii;
use yii\console\Controller;

class TestController extends Controller
{
    public function actionIndex()
    {
        Yii::error('run console action' . json_encode($this->request));

        return 0;
    }
}
