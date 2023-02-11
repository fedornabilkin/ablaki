<?php

use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\CommissionSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $types array */

$this->title = Yii::t('app', 'Commission');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="commission-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],
            'amount',
            [
                'attribute' => 'type',
                'filter' => $types,
            ],
            [
                'attribute' => 'created_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],

//            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
