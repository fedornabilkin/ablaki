<?php

use common\models\user\Person;
use yii\grid\GridView;
use yii\helpers\Html;

/**
 * @var $this yii\web\View
 * @var $searchModel backend\models\PersonSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

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
                'value' => function (Person $model) {
                    return Html::a($model->user->username, ['view', 'id' => $model->id]) . '</a><br>
                        <span class="text-success">' . $model->balance_out . '</span>
                        <span class="text-danger">' . $model->balance_in . '</span>';
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
            'bonus_count',
        ],
    ]); ?>
</div>
