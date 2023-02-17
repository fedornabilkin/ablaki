<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\modules\forum\models\ForumTheme $model */

$this->title = Yii::t('forum', 'Create Forum Theme');
$this->params['breadcrumbs'][] = ['label' => Yii::t('forum', 'Forum Themes'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="forum-theme-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
