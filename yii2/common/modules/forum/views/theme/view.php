<?php

use common\modules\forum\models\ForumTheme;
use yii\helpers\Html;
use yii\web\YiiAsset;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumTheme $model */

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => Yii::t('forum', 'Forum Themes'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
YiiAsset::register($this);
?>
<div class="forum-theme-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('forum', 'Update'), ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a(Yii::t('forum', 'Delete'), ['delete', 'id' => $model->id], [
            'class' => 'btn btn-danger',
            'data' => [
                'confirm' => Yii::t('forum', 'Are you sure you want to delete this item?'),
                'method' => 'post',
            ],
        ]) ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            [
                'attribute' => 'user_id',
                'value' => static function (ForumTheme $model) {
                    return $model->user->username;
                }
            ],
            'title',
            'view',
            [
                'attribute' => 'last_post',
                'format' => ['date', 'php:H:i d.m.y']
            ],
            [
                'attribute' => 'created_at',
                'format' => ['date', 'php:H:i d.m.y']
            ],
        ],
    ]) ?>

</div>
