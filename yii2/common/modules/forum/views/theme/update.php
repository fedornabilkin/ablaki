<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumTheme $model */

$this->title = Yii::t('forum', 'Update Forum Theme: {name}', [
    'name' => $model->title,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('forum', 'Forum Themes'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->title, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('forum', 'Update');
?>
<div class="forum-theme-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
