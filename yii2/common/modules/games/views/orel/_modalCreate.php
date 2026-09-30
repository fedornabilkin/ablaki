<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 21:58
 *
 * @var $this yii\web\View
 */

use common\modules\games\models\GameOrel;
use yii\bootstrap\ActiveForm;
use yii\helpers\Html;
use yii\helpers\Url;

$simpleKon = [
    1,
    5,
    10,
    20,
    50,
    100,
//    0.5,
//    1,
//    2,
];
$simpleKolvo = [
//    3,
//    4,
    5,
    10,
    20,
    30,
    50,
//    80,
    100
];

$model = new GameOrel();
?>

<div class="modal fade orel-create">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title"><?=Yii::t('games', 'Create games')?></h4>
            </div>
            <div class="modal-body">
                <?php $form = ActiveForm::begin([
                    'action' => Url::to(['create']),
                    'options' => [
                        'id' => 'game-create',
                        'data-request' => 'ajax',
                        'data-alert' => '.resp',
                    ],

                ]); ?>

                <div class="row">
                    <div class="col-xs-12 col-sm-6">
                        <?= $form->field($model, 'kon')->input('number', [
                            'step' => 1,
                            'min' => 1,
                            'max' => 10000,
                            'value' => 10,
                        ]) ?>
                    </div>
                    <div class="col-xs-12 col-sm-6">
                        <?= $form->field($model, 'count')->input('number', [
                            'step' => 1,
                            'min' => 1,
                            'max' => 100,
                            'value' => 1,
                        ]) ?>
                    </div>
                    <div class="clearfix"></div>
                    <div class="col-xs-12 col-sm-6">
                        <div class="small simple-kon form-group">
                            <?php foreach($simpleKon as $kon):?>
                                <span class="pointer tag tag-pill tag-info"><?=$kon?></span>
                            <?php endforeach;?>
                        </div>
                    </div>
                    <div class="col-xs-12 col-sm-6">
                        <div class="small simple-kolvo form-group">
                            <?php foreach($simpleKolvo as $kolvo):?>
                                <span class="pointer tag tag-pill tag-info"><?=$kolvo?></span>
                            <?php endforeach;?>
                        </div>
                    </div>
                </div>

                <br>
                <div class="form-group">
                    <?= Html::submitButton(Yii::t('app', 'Create'), ['class' => 'btn btn-success']) ?>
                </div>
                <?= Html::tag('div', '', ['class' => 'resp'])?>

                <?php ActiveForm::end(); ?>
            </div>
        </div>
    </div>
</div>