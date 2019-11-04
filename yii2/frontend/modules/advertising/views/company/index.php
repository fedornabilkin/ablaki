<?php

use kartik\switchinput\SwitchInput;
use yii\helpers\Html;
use yii\grid\GridView;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $searchModel frontend\modules\advertising\models\AdvertisingSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */


$this->title = Yii::t('advertising', 'Advertisings');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="advertising-index">

    <p>
        <?= Html::a(Yii::t('advertising', 'Create Advertising'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
//        'rowOptions' => function ($model, $key, $index, $grid) {
//            $class = ($model->status != 1) ? ['class' => 'warning'] : '';
//            $class = ($model->approve != 1) ? ['class' => 'danger'] : $class;
//            return $class;
//        },
        'columns' => [
//            ['class' => 'yii\grid\SerialColumn'],

//            'id',
//            'user_id',
            [
                'label' => \Yii::t('advertising', 'Info'),
                'format' => 'raw',
                'value' => function($model){
//                    $switch = '<span class="switch-toggle on fa fa-toggle-on text-success"></span>';
//                    $switch .= '<span class="switch-toggle off fa fa-toggle-off text-muted"></span>';
                    $switch = SwitchInput::widget([
                        'name' => 'status_' . $model->id,
//                        'disabled' => rand(0,1),
//                        'tristate' => true,
//                        'type' => SwitchInput::CHECKBOX,
                        'value' => $model->status,
//                        'items' => [
//                            ['label' => 'Low', 'value' => 1],
//                        ],
                        'pluginEvents' => [
                            'switchChange.bootstrapSwitch' => 'function(event, state) {
                                $.ajax({
                                    method: "POST",
                                    url: "'.Url::to(['switch', 'id' => $model->id]).'",
                                    data: { status: state}
                                })
                            }',
                        ],
                        'pluginOptions' => [
                            'size' => 'mini',
                            'onColor' => 'success',
                            'onText' => \Yii::t('advertising', 'On'),
                            'offText' => \Yii::t('advertising', 'Off'),
                        ],
                        'labelOptions' => ['style' => 'font-size: 12px'],
                    ]);
                    $str = $switch . ' ' . $model->credit;
                    return $str;
                }
            ],
//            'credit',
//            [
//                'label' => \Yii::t('advertising', 'Info'),
//                'value' => function($model){
//                    $str = $model->credit . ' ' . $model->clicks . ' ' . $model->views;
//                    return $str;
//                }
//            ],
            [
                'label' => \Yii::t('advertising', 'Title'),
                'attribute' => 'title',
                'format' => 'raw',
                'value' => function($model){
                    $link = Html::a($model->title, $model->url, ['target' => '_blank']);
                    $stat = '<i class="fa fa-arrow-circle-down"></i> ' . $model->clicks . ' <i class="fa fa-eye"></i> ' . $model->views;
                    return $link . '<br>' . $stat;
                }
            ],
//            'description',
//            'approve',
//            'status',
//            'url:url',
//            'banner',
            //'position',
//            'hash',
            //'type',
//            'clicks',
//            'views',
            //'comment',
            //'updated_at',
            //'created_at',
            [
                'class' => \common\utilities\widgets\gridview\TestColumn::class,
                'label' => 'Test',
            ],

            ['class' => 'yii\grid\ActionColumn'],
        ],
    ]); ?>
</div>
