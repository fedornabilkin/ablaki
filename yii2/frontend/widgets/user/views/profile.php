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

$avatar = 'noavatar.png';
if($user->username && file_exists('@frontend/web/img/avatar/'.$user->username)){
    $avatar = $user->username;
}

$star = \common\helpers\UserHelper::ratingStar($user->person->rating);
$rating = \common\helpers\UserHelper::ratingRound($user->person->rating);

$loginUrl = Html::a($user->username, Url::to(['/user/wall', 'login' => $user->username]));
?>

<div class="user-profile">
    <div class="">
        <?=$loginUrl?>
        <span class="rating small">
            <span class="fa fa-star <?=$star['star_class']?>" aria-hidden="true"></span>
            <span class="amount"><?= $rating ?></span>
            <span class="label label-success pointer" href="/rating/up" data-type="ajax">+</span>
        </span>
    </div>
    <img class="avatar img-rounded img-thumbnail" src="/img/avatar/<?= $avatar ?>" alt="Avatar <?= $user->username?>">
</div>
