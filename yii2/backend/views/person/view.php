<?php

use common\models\user\Person;
use yii\helpers\Html;
use yii\web\YiiAsset;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model Person */

$this->title = $model->user->username;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Person'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
YiiAsset::register($this);
?>
<div class="todo-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            [
                'attribute' => 'username',
                'value' => function (Person $model) {
                    return $model->user->username;
                }
            ],
            'balance',
            'credit',
            [
                'attribute' => 'refovod',
                'format' => 'raw',
                'value' => function (Person $model) {
                    return Html::a($model->refovodUser->username, ['view', 'id' => $model->refovodUser->person->id]);
                }
            ],
            'rating',
            'referrer',
            'bonus_count',
            'autoriz',
//            [
//                'attribute' => 'updated_at',
//                'format' => ['date', 'php:H:i d.m.y']
//            ],
//            [
//                'attribute' => 'created_at',
//                'format' => ['date', 'php:H:i d.m.y']
//            ],
        ],
    ]) ?>

</div>
