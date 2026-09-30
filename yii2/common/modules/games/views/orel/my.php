<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 14.07.2018
 * Time: 13:49
 *
 * @var $this yii\web\View
 * @var $searchModel common\modules\games\models\OrelSearch
 * @var $dataProvider yii\data\ActiveDataProvider
 */

use yii\helpers\Html;
use yii\grid\GridView;
use yii\helpers\Url;

\common\modules\games\assets\OrelAsset::register($this);

$this->title = Yii::t('games', 'My games');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="game-orel-my">

    <?= $this->render('_buttons')?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
//        'filterModel' => $searchModel,
        'layout'=>"{summary}\n{items}",
        'columns' => [
            [
                'class' => \common\utilities\widgets\gridview\UserColumn::class,
                'label' => Yii::t('games', 'Created'),
                'format' => 'raw',
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
</div>

<?= $this->render('_modalCreate')?>