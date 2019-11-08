<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 13:35
 *
 * @var $this yii\web\View
 * @var $searchModel \common\modules\games\models\SaperSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;
?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
//        'filterModel' => $searchModel,
    'layout'=>"{summary}\n{items}",
    'rowOptions' => function ($model, $key, $index, $grid) {
        return (is_object($model) && $model->user_gamer > 0) ? ['class' => 'warning'] : [];
    },
    'columns' => [
//        'id',
        [
            'class' => \common\utilities\widgets\gridview\UserColumn::class,
            'label' => Yii::t('games', 'Created'),
        ],
        [
            'label' => Yii::t('games', 'Kon'),
            'format' => 'raw',
            'value' => function($data){
                return Html::tag('span', '', [
                    'class' => 'pointer fa fa-gamepad text-success',
                    'data-toggle' => 'modal',
                    'data-target' => '.saper-play',
                    'data-gid' => $data->id,
                    'data-kon' => $data->kon,
                    'title' => Yii::t('games', 'Play'),
                ]) . ' ' . $data->kon;
            }
        ],
        [
            'class' => \common\utilities\widgets\gridview\TestColumn::class,
            'label' => 'Test',
        ],
        'created_at:date',
    ],
]); ?>