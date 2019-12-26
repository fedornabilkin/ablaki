<?php

use common\models\HistoryBalance;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\web\YiiAsset;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model common\models\user\Person */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'People'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
YiiAsset::register($this);
?>
<div class="person-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            'user_id',
            'balance',
            'balance_in',
            'balance_out',
            'credit',
            'refovod',
            'rating',
            'referrer:ntext',
            'bonus_count',
            'autoriz',
        ],

    ]) ?>

</div>

<div class="history-balance-index">

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
                'value' => function ($model) {
                    return Html::tag('span', $model->balance_up . '<br>' . $model->credit_up . (($model->credit_up >= 0)), [
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
