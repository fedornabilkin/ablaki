<?php
/**
 * @var $this yii\web\View
 * @var $searchModel \common\modules\games\models\SaperSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

$this->title = Yii::t('games', 'My games');
?>

<?= $this->render('_layout', [
    'dataProvider' => $dataProvider,
    'searchModel' => $searchModel,
]) ?>