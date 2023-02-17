<?php

use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\UserColumn;
use common\modules\forum\models\ForumTheme;
use yii\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ThemeSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('forum', 'Forum Themes');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="forum-theme-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('forum', 'Create Forum Theme'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            'id',
            [
                'attribute' => 'user_id',
                'class' => UserColumn::class,
            ],
            [
                'attribute' => 'title',
                'format' => 'raw',
                'value' => static function (ForumTheme $model) {
                    return Html::a($model->title, ['view', 'id' => $model->id]);
                }
            ],
            'view',
            [
                'attribute' => 'last_post',
                'class' => CreatedAtColumn::class
            ],
            [
                'attribute' => 'created_at',
                'class' => CreatedAtColumn::class
            ],
        ],
    ]); ?>


</div>
