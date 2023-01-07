<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 09.01.2022
 * Time: 16:00
 */

use backend\widgets\gridView\columns\BuyerColumn;
use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\UpdatedAtColumn;
use backend\widgets\gridView\columns\UserColumn;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel common\modules\exchange\models\CreditExchangeSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('exchange', 'Credit Exchanges');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="credit-exchange-index">

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
            'credit',
            'amount',
            [
                'attribute' => 'type',
                'filter' => $searchModel->availableTypes(),
            ],
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
