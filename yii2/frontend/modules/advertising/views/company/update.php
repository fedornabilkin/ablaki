<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model frontend\modules\advertising\models\Advertising */

$this->title = Yii::t('advertising', 'Update Advertising: ' . $model->title, [
    'nameAttribute' => '' . $model->title,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('advertising', 'Advertisings'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->title, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('advertising', 'Update');
?>
<div class="advertising-update">

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
