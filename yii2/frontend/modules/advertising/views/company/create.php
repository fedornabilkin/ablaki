<?php

use yii\helpers\Html;


/* @var $this yii\web\View */
/* @var $model frontend\modules\advertising\models\Advertising */

$this->title = Yii::t('advertising', 'Create Advertising');
$this->params['breadcrumbs'][] = ['label' => Yii::t('advertising', 'Advertisings'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="advertising-create">

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
