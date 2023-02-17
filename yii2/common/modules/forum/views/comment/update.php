<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumComment $model */

$this->title = Yii::t('forum', 'Update Forum Comment: {name}', [
    'name' => $model->id,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('forum', 'Forum Comments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('forum', 'Update');
?>
<div class="forum-comment-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
