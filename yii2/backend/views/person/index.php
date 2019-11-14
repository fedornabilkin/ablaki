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
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>



    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],


            ['attribute' => 'user_id',
                'value' => function ($model) {
                    return $model->user->username . '<br><p class="text-success">' . $model->balance_out . '</p> <p class="text-danger">' . $model->balance_in . '</p>';
            }
            ],
            'balance',
//            'balance_in',//
//            \'balance_out\',
            'credit',
//            'refovod',
            ['attribute' => 'refovod',
                'value' => function ($model) {
                    return $model->refovodUser->username;
                                }
            ],
            'rating',
            //'referrer:ntext',
            //'bonus_count',
            //'autoriz',

//            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
