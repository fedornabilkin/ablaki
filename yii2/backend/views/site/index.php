<?php

/**
 * @var $this yii\web\View
 * @var $todoProvider ActiveDataProvider
 * @var $personProvider ActiveDataProvider
 * @var $themeProvider ActiveDataProvider
 * @var $commission array
 */

use yii\data\ActiveDataProvider;
use yii\helpers\Html;

$this->title = Yii::$app->name;
?>
<div class="site-index">

    <!--    <div class="jumbotron">-->
    <!--        <h1>Congratulations!</h1>-->
    <!---->
    <!--        <p class="lead">You have successfully created your Yii-powered application.</p>-->
    <!--    </div>-->

    <div class="body-content">

        <div class="row">
            <div class="col-lg-4">
                <h2><?= Yii::t('app', 'Todos'); ?></h2>

                <?php foreach ($todoProvider->models as $model): ?>

                    <p><?= Html::a($model->title, ['/todo/view', 'id' => $model->id]) ?></p>

                <?php endforeach; ?>

                <p><?= Html::a(Yii::t('app', 'View all'), ['/todo/'], ['class' => 'btn btn-default']) ?></p>
            </div>
            <div class="col-lg-4">
                <h2><?= Yii::t('app', 'Person') ?></h2>

                <?php foreach ($personProvider->models as $model): ?>

                    <p><?= Html::a($model->user->username, ['/person/view', 'id' => $model->id]) ?></p>

                <?php endforeach; ?>

                <p><?= Html::a(Yii::t('app', 'View all'), ['/person'], ['class' => 'btn btn-default']) ?></p>
            </div>
            <div class="col-lg-4">
                <h2><?= Yii::t('app', 'Commission') ?></h2>

                <?php
                //                    var_dump($commission);
                ?>
                <p>game_saper: <?= $commission['game_saper']['amount'] ?> Kg</p>
                <p>game_orel: <?= $commission['game_orel']['amount'] ?> Cr</p>
                <p>game_duel: <?= $commission['game_duel']['amount'] ?> Cr</p>

                <p><?= Html::a(Yii::t('app', 'View all'), ['/comission/'], ['class' => 'btn btn-default']) ?></p>
            </div>
        </div>

        <div class="row">

            <div class="col-lg-4">
                <h2><?= Yii::t('app', 'Forum Theme'); ?></h2>

                <?php foreach ($themeProvider->models as $model): ?>

                    <p><?= Html::a($model->title, ['/forum/theme/view', 'id' => $model->id]) ?></p>

                <?php endforeach; ?>

                <p><?= Html::a(Yii::t('app', 'View all'), ['/forum/theme/'], ['class' => 'btn btn-default']) ?></p>
            </div>
        </div>

    </div>
</div>
