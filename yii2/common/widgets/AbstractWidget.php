<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 20:58
 */

namespace common\widgets;


use yii\base\Widget;

abstract class AbstractWidget extends Widget
{
    public function init() {
        parent::init();

        $view = $this->getView();
        $this->registerAssets($view);
    }

    abstract function registerAssets($view);
}