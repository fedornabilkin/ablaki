<?php

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * @var $this yii\web\View
 * @var $searchModel common\modules\games\models\OrelSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

\common\modules\games\assets\OrelAsset::register($this);

$this->title = Yii::t('games', 'Game Orels');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="game-orel-index">

    <?= $this->render('_buttons')?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
//        'filterModel' => $searchModel,
        'layout'=>"{summary}\n{items}",
        'columns' => [
            [
                'class' => \common\utilities\widgets\gridview\UserColumn::class,
                'label' => Yii::t('games', 'Created'),
            ],
            [
                'label' => Yii::t('games', 'Kon'),
                'format' => 'raw',
                'value' => function($data){
                    $str = $data->kon;
                    $str .= Html::a('', Url::to(['play', 'id' => $data->id]), [
                        'class' => 'fa fa-2x fa-circle-o text-info pull-right',
                        'data-request' => 'ajax',
                        'data-hod' => 2,
                        'title' => Yii::t('games', 'Play'),
                    ]);
                    $str .= Html::a('', Url::to(['play', 'id' => $data->id]), [
                        'class' => 'fa fa-2x fa-circle text-info pull-right',
                        'data-request' => 'ajax',
                        'data-hod' => 1,
                        'title' => Yii::t('games', 'Play'),
                    ]);
                    return $str;
                }
            ],
            [
                'class' => \common\utilities\widgets\gridview\TestColumn::class,
                'label' => 'Test',
            ],
            'created_at:date',
        ],
    ]); ?>
</div>

<?= $this->render('_modalCreate')?>