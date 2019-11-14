<?php

use yii\helpers\Html;
use yii\grid\GridView;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\PersonSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'People');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="person-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],


            [
                'attribute' => 'user_id',
                'format' => 'raw',
                'value' => function ($model) {
                    return $model->user->username . '<br>
                        <span class="text-success">' . $model->balance_out . '</span>
                        <span class="text-danger">' . $model->balance_in . '</span>';
            }
            ],
            'balance',
            'credit',
            [
                'attribute' => 'refovod',
                'value' => function ($model) {
                    return $model->refovodUser->username;
                }
            ],
            'rating',
            'bonus_count',
        ],
    ]); ?>
</div>
