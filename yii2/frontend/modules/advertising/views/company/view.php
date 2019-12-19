<?php

use kartik\switchinput\SwitchInput;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model frontend\modules\advertising\models\Advertising */

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => Yii::t('advertising', 'Advertisings'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="advertising-view">

    <p>
        <?= Html::a(Yii::t('advertising', 'Update'), ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a(Yii::t('advertising', 'Delete'), ['delete', 'id' => $model->id], [
            'class' => 'btn btn-danger',
            'data' => [
                'confirm' => Yii::t('advertising', 'Are you sure you want to delete this item?'),
                'method' => 'post',
            ],
        ]) ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
//            'id',
//            'user_id',
//            'approve',
//            'status',

            [
                'label' => \Yii::t('advertising', 'Status'),
                'format' => 'raw',
                'value' => function($model){
                    return SwitchInput::widget([
                        'name' => 'status_' . $model->id,
                        'value' => $model->status,
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
                }
            ],
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
//            'title',
            'description',
            'url:url',
//            'banner',
//            'position',
//            'hash',
            'credit',
//            'type',
//            'clicks',
//            'views',
            'comment',
            'updated_at:datetime',
            'created_at:datetime',
        ],
    ]) ?>

</div>
