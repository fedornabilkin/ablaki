<?php

use common\models\Fact;
use yii\grid\GridView;
use yii\helpers\Html;

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

            [
                'attribute' => 'title',
                'format' => 'raw',
                'value' => static function (Fact $model) {
                    return Html::a($model->title, ['view', 'id' => $model->id]);
                }
            ],
            'type',
            [
                'attribute' => 'hide',
                'format' => 'raw',
                'filter' => ['Вкл', 'Выкл',],
                'value' => function ($model) {
                    return $model->hide ? 'Выкл' : 'Вкл';
                },
            ],

//            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
