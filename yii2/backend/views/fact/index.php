<?php

use yii\helpers\Html;
use yii\grid\GridView;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\FactSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Facts');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="fact-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create Fact'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            'title',
            'type',
            [
                'attribute' => 'hide',
                'format' => 'raw',
                 'filter' => ['ВЫКЛ', 'ВКЛ'],
                'value' => function ($model) {
                    return $model->hide ? "ВКЛ" : "ВЫКЛ" ;
                },
            ],


//            'hide',

            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
