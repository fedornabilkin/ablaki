<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 21:03
 *
 * @var $this yii\web\View
 * @var $user \common\models\user\User
 */

use yii\helpers\Html;
use yii\helpers\Url;


$loginUrl = Html::a($user->username, Url::to(['/user/wall', 'login' => $user->username]));
?>

<div class="user-bar">
    <div class="balans pull-left m-r-1">
        <span class="amount"><?=  round($user->person->balance, 2)?></span>
        <span>Кг</span>
    </div>
    <div class="credit pull-left">
        <span class="amount">
        <?= ($user->person->credit > 100) ? round($user->person->credit): round($user->person->credit, 2)?>
        </span>
        <span>Cr</span>
    </div>
    <div class="clearfix"></div>
</div>
