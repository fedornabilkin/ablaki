<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 26.05.2018
 * Time: 14:21
 *
 * @var $this yii\web\View
 */

$user = Yii::$app->user->identity;
?>

<div class="block">
    <a class="users-online m-b-1 no-underline text-default" href="/check/online" data-type="ajax" data-handler="usersOnline" data-alert=".users-online .amount" title="Пользователи онлайн">
        <span class="fa fa-users" aria-hidden="true"></span>
        <span class="amount" data-url="/check" data-handler="usersOnlineCount">0</span>
    </a>
</div>

<div class="block">
    <?=\frontend\modules\advertising\widgets\ads\AdsWidget::widget()?>
</div>

<?php if($user):?>
<div class="block">
<?= \frontend\widgets\user\Profile::widget(['user' => $user])?>
<?= \frontend\widgets\user\Userbar::widget(['user' => $user])?>
</div>
<?php endif?>

<div class="block">Призовой фонд</div>
<div class="">Слайдер</div>
<div class="">Последнее на форуме</div>