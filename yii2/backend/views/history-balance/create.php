<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model common\models\HistoryBalance */

$this->title = Yii::t('app', 'Create History Balance');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'History Balances'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="history-balance-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
