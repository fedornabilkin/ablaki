<?php

/**
 * @var $this yii\web\View
 * @var $todoProvider ActiveDataProvider
 * @var $personProvider ActiveDataProvider
 */

use yii\data\ActiveDataProvider;
use yii\helpers\Html;

$this->title = Yii::$app->name;
?>
<div class="site-index">

    <div class="jumbotron">
        <h1>Congratulations!</h1>

        <p class="lead">You have successfully created your Yii-powered application.</p>
    </div>

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
                <h2>Commission</h2>

                <p>Today</p>
                <p>Yesterday</p>

            </div>
        </div>

    </div>
</div>
