<?php

use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\HistoryCommentColumn;
use backend\widgets\gridView\columns\UserColumn;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\history\HistoryRatingSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $historyTypes array */

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
            'rating_up',
            [
                'attribute' => 'type',
                'filter' => $historyTypes,
            ],
            [
                'attribute' => 'comment',
                'class' => HistoryCommentColumn::class
            ],
            [
                'attribute' => 'created_at',
                'class' => CreatedAtColumn::class
            ],

        ],
    ]); ?>
</div>
