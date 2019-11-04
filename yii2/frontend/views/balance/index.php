<?php

use yii\helpers\Html;
use yii\grid\GridView;

/**
 * @var $this yii\web\View
 * @var $searchModel frontend\models\HistoryBalanceSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

$this->title = Yii::t('app', 'History Balances');
$this->params['breadcrumbs'][] = $this->title;

$this->registerJs('$("[data-toggle=\"popover\"]").popover()');
?>
<div class="history-balance-index">

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            [
                'class' => \common\utilities\widgets\gridview\AuditColumn::class,
                'attribute' => 'created_at',
            ],
//            ['class' => 'yii\grid\SerialColumn'],
//            'id',
//            'user_id',
            [
                'attribute' => 'balance',
                'format' => 'raw',
                'value' => function($model){
                    $value = round($model->balance, 3);
                    $up = round($model->balance_up, 5);
                    $class = $up > 0 ? 'text-success' : 'text-danger';
                    $up = $up > 0 ? '+' . $up : $up;
                    return $value . ' ' . Html::tag('span', $up, ['class' => $class]);
                }
            ],
            [
                'attribute' => 'credit',
                'format' => 'raw',
                'value' => function($model){
                    $value = round($model->credit, 3);
                    $up = round($model->credit_up, 5);
                    $class = $up > 0 ? 'text-success' : 'text-danger';
                    $up = $up > 0 ? '+' . $up : $up;
                    return $value . ' ' . Html::tag('span', $up, ['class' => $class]);
                }
            ],
//            'balance_up',
//            'credit_up',
//            'type',
//            'comment',
//            'created_at:time',
        ],
    ]); ?>
</div>
