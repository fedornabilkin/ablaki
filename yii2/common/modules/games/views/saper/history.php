<?php
/**
 * @var $this yii\web\View
 * @var $searchModel \common\modules\games\models\SaperSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

$this->title = Yii::t('games', 'Games complete');
?>

<?= $this->render('_layout', [
    'dataProvider' => $dataProvider,
    'searchModel' => $searchModel,
])?>