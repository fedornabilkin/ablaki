<?php
use common\modules\world\admin\ControlCatalog;
use yii\helpers\Html;
\common\modules\world\assets\ControlAsset::register($this);
$this->title=$title ?? 'Управление миром';
$this->params['breadcrumbs'][]=['label'=>'Мир','url'=>['/world/control/index']];
if($this->title!=='Управление миром') $this->params['breadcrumbs'][]=$this->title;
?>
<header class="wc-heading"><p><i class="fa fa-globe" aria-hidden="true"></i>Экономика, развитие и имущество игроков</p><div class="wc-actions"><?= Html::a('<i class="fa fa-refresh" aria-hidden="true"></i> Обновить',Yii::$app->request->url,['class'=>'btn btn-default']) ?></div></header>
<nav class="wc-nav" aria-label="Управление миром">
<?php foreach(ControlCatalog::groups() as $id=>[$label,$icon]): ?>
<?= Html::a('<i class="fa fa-'.$icon.'" aria-hidden="true"></i>'.Html::encode($label),['index','group'=>$id],['class'=>($group ?? 'overview')===$id?'active':'','aria-current'=>($group ?? 'overview')===$id?'page':null]) ?>
<?php endforeach ?>
</nav>
