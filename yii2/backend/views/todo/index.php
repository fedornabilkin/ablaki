<?php

use backend\widgets\gridView\columns\UserColumn;
use common\models\Todo;
use yii\grid\GridView;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\TodoSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Todos');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="todo-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create Todo'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

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
                'attribute' => 'title',
                'format' => 'raw',
                'value' => static function (Todo $model) {
                    return Html::a($model->title, ['view', 'id' => $model->id]);
                }
            ],
//            [
//                'attribute' => 'comment',
//                'value' => static function (Todo $model) {
//                    return StringHelper::truncate($model->comment, 100);
//                }
//            ],
            [
                'attribute' => 'status',
                'format' => 'raw',
                'filter' => ['Ready', 'Ok'],
                'value' => function (Todo $model) {
                    return $model->status ? 'Ok' : 'Ready';
                },
            ],
            [
                'attribute' => 'created_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],
            [
                'attribute' => 'updated_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],

//            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
