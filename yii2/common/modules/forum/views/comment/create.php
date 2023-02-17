<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumComment $model */

$this->title = Yii::t('forum', 'Create Forum Comment');
$this->params['breadcrumbs'][] = ['label' => Yii::t('forum', 'Forum Comments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="forum-comment-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
