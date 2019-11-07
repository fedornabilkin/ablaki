<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 13:44
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
    'columns' => [
        [
            'class' => \common\utilities\widgets\gridview\UserColumn::class,
            'label' => Yii::t('games', 'Created'),
        ],
        'kon',
        [
            'label' => Yii::t('app', 'Action'),
            'format' => 'raw',
            'value' => function($data){
                $anchor = '<span class="fa fa-trash text-danger"></span>';
                $url = Url::to(['remove', 'id' => $data->id]);
                return Html::a($anchor, $url, [
                    'data-request' => 'ajax',
                ]);
            }
        ],
        [
            'class' => \common\utilities\widgets\gridview\TestColumn::class,
            'label' => 'Test',
        ],
        'created_at:date',
    ],
]); ?>