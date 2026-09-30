<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 14.07.2018
 * Time: 14:10
 */

use yii\helpers\Html;
use yii\grid\GridView;
use yii\helpers\Url;

/**
 * @var $this yii\web\View
 * @var $searchModel common\modules\games\models\OrelSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

\common\modules\games\assets\OrelAsset::register($this);

$this->title = Yii::t('games', 'Games complete');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="game-orel-index">

    <?= $this->render('_buttons')?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
//        'filterModel' => $searchModel,
        'columns' => [
            [
                'class' => \common\utilities\widgets\gridview\UserGamerColumn::class,
                'label' => Yii::t('games', 'Players'),
                'isWin' => function($model){
                    return $model->isWin();
                },
            ],
            [
                'class' => \common\utilities\widgets\gridview\TestColumn::class,
                'label' => 'Test',
            ],
            'kon',
            'created_at:date',
            'updated_at:datetime',
        ]
    ]); ?>
</div>