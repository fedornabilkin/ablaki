<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 13:32
 *
 * @var $this yii\web\View
 * @var $searchModel \common\modules\games\models\SaperSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */


\common\modules\games\assets\SaperAsset::register($this);

$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_buttons')?>

<?= $this->render('grids/' . $this->context->action->id, [
    'dataProvider' => $dataProvider,
    'searchModel' => $searchModel,
])?>

<?= $this->render('_modalPlay')?>
<?= $this->render('_modalCreate')?>