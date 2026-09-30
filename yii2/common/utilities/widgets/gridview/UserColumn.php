<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.07.2018
 * Time: 22:27
 */

namespace common\utilities\widgets\gridview;


use yii\helpers\Html;
use yii\helpers\Url;

/**
 * @deprecated
 */
class UserColumn extends AbstractColumn
{

    protected function makeCellContent($model)
    {
        $login = $model->user->username;
        $url = Url::to(['/user/wall/', 'login' => $login]);
        return Html::a($login, $url);
    }

}
