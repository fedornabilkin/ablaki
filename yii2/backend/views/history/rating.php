<?php

use backend\widgets\gridView\columns\UserColumn;
use common\services\history\HistoryService;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\history\HistoryRatingSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'History Ratings');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="history-balance-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            [
                'attribute' => 'user_id',
                'class' => UserColumn::class,
            ],
            'rating',
            [
                'attribute' => 'type',
                'filter' => HistoryService::getTypes(),
            ],
            'comment',
            [
                'attribute' => 'created_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],

        ],
    ]); ?>
</div>
