<?php

use backend\models\history\HistoryBalanceSearch;
use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\HistoryCommentColumn;
use backend\widgets\gridView\columns\UserColumn;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel HistoryBalanceSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $historyTypes array */

$this->title = Yii::t('app', 'History Balances');
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
            [
                'attribute' => 'balance',
                'format' => 'raw',
                'value' => function ($model) {
                    return $model->balance . '<br>
                        <span class="text-success">' . $model->credit . '</span>';
                }
            ],
            [
                'attribute' => 'balance_up',
                'format' => 'raw',
                'value' => function ($model) {
                    return Html::tag('span', $model->balance_up . '<br>'
                        . $model->credit_up, [
                        'class' => ($model->credit_up >= 0) ? 'text-success' : 'text-danger']);
                }
            ],
            [
                'attribute' => 'type',
                'filter' => $historyTypes,
            ],
//            [
//                'attribute' => 'type',
//                'filter' => Html::activeDropDownList(
//                    $searchModel,
//                    'type',
//                    HistoryBalance::getSortLabels(),
//                    [
//                        'class' => 'form-control form-control-sm'
//                    ]
//                ),
//            ],
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
