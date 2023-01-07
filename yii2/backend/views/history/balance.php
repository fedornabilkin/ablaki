<?php

use backend\widgets\gridView\columns\UserColumn;
use common\services\history\HistoryService;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\history\HistoryBalanceSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

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
                'filter' => HistoryService::getTypes(),
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
            'comment',
            [
                'attribute' => 'created_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],

        ],
    ]); ?>
</div>
