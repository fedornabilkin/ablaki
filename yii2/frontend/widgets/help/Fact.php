<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 01.08.2018
 * Time: 22:15
 */

namespace frontend\widgets\help;


use common\widgets\AbstractWidget;
use yii\bootstrap\Alert;

class Fact extends AbstractWidget
{
    public $type = 'alert-info';

    public function run()
    {
        $fact = $this->getFact();
        if(!$fact){
            return '';
        }

        $body = '<span class="fa fa-exclamation-circle" aria-hidden="true"></span> ';
        $body .= $fact->title;

        return Alert::widget([
            'options' => [
                'class' => $this->type,
            ],
            'body' => $body,
        ]);
    }

    protected function getFact()
    {
        $models = \common\models\Fact::find()->where(['!=', 'hide', '1'])->all();
        shuffle($models);
//        return \common\models\Fact::find()->where(['!=', 'hide', '1'])->orderBy('RAND()')->one();
        return $models[0];
    }

    function registerAssets($view)
    {

    }
}