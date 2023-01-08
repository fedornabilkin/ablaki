<?php

use backend\widgets\gridView\columns\BuyerColumn;
use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\UpdatedAtColumn;
use backend\widgets\gridView\columns\UserColumn;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel common\modules\exchange\models\CreditTransferSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('exchange', 'Credit Transfers');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="credit-transfer-index">

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
                'attribute' => 'user_buyer',
                'class' => BuyerColumn::class,
            ],
            'amount',
            [
                'attribute' => 'created_at',
                'class' => CreatedAtColumn::class
            ],
            [
                'attribute' => 'updated_at',
                'class' => UpdatedAtColumn::class
            ],
        ],
    ]); ?>
</div>
