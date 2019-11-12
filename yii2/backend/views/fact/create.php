<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model common\models\Fact */

$this->title = Yii::t('app', 'Create Fact');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Facts'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="fact-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
