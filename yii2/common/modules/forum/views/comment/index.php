<?php

use backend\widgets\gridView\columns\CreatedAtColumn;
use backend\widgets\gridView\columns\UserColumn;
use common\modules\forum\models\ForumComment;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\StringHelper;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\CommentSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('forum', 'Forum Comments');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="forum-comment-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('forum', 'Create Forum Comment'), ['create'], ['class' => 'btn btn-success']) ?>
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
                'attribute' => 'theme_id',
                'format' => 'raw',
                'value' => static function (ForumComment $model) {
                    return Html::a($model->theme_id . ' ' . $model->theme->title, ['theme/view', 'id' => $model->theme_id]);
                }
            ],
            [
                'attribute' => 'comment',
                'format' => 'raw',
                'value' => static function (ForumComment $model) {
                    $comment = StringHelper::truncate($model->comment, 100);
                    return Html::a($comment, ['view', 'id' => $model->id]);
                }
            ],
            'active',
            [
                'attribute' => 'created_at',
                'class' => CreatedAtColumn::class
            ],
        ],
    ]); ?>


</div>
