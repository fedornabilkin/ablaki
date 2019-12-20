<?php

use common\models\HistoryBalance;
use yii\grid\GridView;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\HistoryBalanceSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'History Balances');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="history-balance-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create History Balance'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            ['attribute' => 'user_id',
                'value' => function ($model) {
                    return $model->user->username;
                }
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
            'value' => function($model) {
                return \yii\helpers\Html::tag('span', $model->balance_up . '<br>' . $model->credit_up . (($model->credit_up >= 0)), [
                  'class' => ($model->credit_up >= 0) ? 'text-success' : 'text-danger'
                ]);
              }
            ],
            [
                'attribute' => 'type',
                'filter' => Html::activeDropDownList(
                    $searchModel,
                    'type',
                    HistoryBalance::getSortLabels(),
                    [
                        'everyday' => 'каждый день',
                        'class' =>
                        'form-control form-control-sm'
                    ]
                ),
            ],
            'comment',

        ],
    ]); ?>
</div>
