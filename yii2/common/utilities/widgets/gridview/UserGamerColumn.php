<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.07.2018
 * Time: 23:15
 */

namespace common\utilities\widgets\gridview;


use yii\helpers\Html;
use yii\helpers\Url;

class UserGamerColumn extends AbstractColumn
{
    public $isWin;

    protected function makeCellContent($model)
    {
        $winner = ['user' => '', 'gamer' => ''];
        $icon = '<span class="fa fa-star text-warning"></span>';

        if(true === $this->getWinValue($model)){
            $winner['gamer'] = $icon;
        }elseif(false === $this->getWinValue($model)){
            $winner['user'] = $icon;
        }

        $login = $model->user->username;
        $loginUrl = Url::to(['/user/wall/', 'login' => $login]);

        $gamer = $model->userGamer->username;
        $gamerUrl = Url::to(['/user/wall/', 'login' => $gamer]);

        $loginLink = $winner['user'] .' '. Html::a($login, $loginUrl);
        $gamerLink = Html::a($gamer, $gamerUrl) .' '. $winner['gamer'];

        $span = $loginLink .' vs '. $gamerLink;
        return Html::tag('div', $span,['class' => 'text-center']);
    }

    protected function getWinValue($model)
    {
        if ($this->content !== null) {
            return call_user_func($this->isWin, $model);
        }
        return null;
    }
}