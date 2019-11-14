<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 13.05.2018
 * Time: 21:06
 *
 * @var $this yii\web\View
 * @var $user \common\models\user\User
 */

use common\helpers\UserHelper;
use yii\helpers\Url;

$stars = [
    'text-default',
    'text-muted',
    'text-primary',
    'text-info',
    'text-success',
    'text-warning',
    'text-danger'
];

$star_spin = UserHelper::ratingStar($user->person->rating);

$this->title = Yii::t('app', 'User wall {attr}', ['attr' => $user->username]);
$this->params['breadcrumbs'][] = $this->title;

?>
<div class="row">
    <div class="col-xs-12 col-sm-3">
        <div class="">

        </div>

        <?php if($user->person->refovod > 0):?>
            <div>
                Рефовод: <a href="/user/wall/<?=$user->person->refovodUser->username?>" target="_blank">
                    <?=$user->person->refovodUser->username?>
                </a>
            </div>
        <?php endif?>

        <?php foreach($stars as $star):?>
            <span class="fa fa-star <?=$star?> <?=($star == $star_spin['star_class']) ? 'fa-spin fa-lg': ''?>" aria-hidden="true"></span>
        <?php endforeach;?>
    </div>
    <div class="col-xs-12 col-sm-5">
        <div class="card">
            <div class="card-block">
                <div title="<?=Yii::t('app', 'Registration date')?>">
                    <span aria-hidden="true" class="fa fa-calendar fa-fw"></span>
                    <time datetime="<?= date('Y-m-dTH:i:s', $user->created_at) ?>">
                        <?= date('d.m.y', $user->created_at) ?>
                    </time>
                </div>
                <div title="<?=Yii::t('app', 'Last activity')?>">
                    <span class="fa fa-clock-o fa-fw"></span>
                    <time datetime="<?= date('Y-m-dTH:i:s', $user->last_login_at) ?>">
                        <?= date('d.m.y H:i', $user->last_login_at) ?>
                    </time>
                </div>
                <div>
                    <?=Yii::t('app', 'Collected bonuses: {attr}', ['attr' => $user->person->bonus_count])?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xs-12 col-sm-4">
        <div class="card">
            <div class="card-block">
                <div class="">
                    Создано тем: 0
                </div>
                <div class="">
                    Комментариев: 0
                </div>
                <div class="">
                    Страниц вики:
                </div>
            </div>
        </div>
    </div>
</div>


<?php if(!Yii::$app->user->isGuest && Yii::$app->user->identity->username == $user->username):?>
    <div class="alert alert-success">
        <label><?=Yii::t('app', 'The link to this page is referral')?></label>
        <input class="form-control" type="text" value="<?=Yii::$app->request->hostInfo . Url::to()?>">
    </div>
<?php endif?>
<hr>
