<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 12:08
 *
 * @var $this yii\web\View
 */

use yii\helpers\Html;
use yii\helpers\Url;

$buttons = [
    [
        'url' => '#',
        'icon' => 'fa fa-plus',
        'label' => Yii::t('games', 'Create games'),
        'options' => [
            'class' => 'btn btn-success',
            'data-toggle' => 'modal',
            'data-target' => '.saper-create',
        ],
    ],
    [
        'url' => Url::to(['/games/saper']),
        'icon' => 'fa fa-apple',
        'label' => Yii::t('games', 'All games'),
        'options' => [
            'class' => 'btn btn-primary',
        ],
    ],
    [
        'url' => Url::to(['/games/saper/my']),
        'icon' => 'fa fa-user',
        'label' => Yii::t('games', 'My games'),
        'options' => [
            'class' => 'btn btn-primary',
        ],
    ],
    [
        'url' => Url::to(['/games/saper/history']),
        'icon' => 'fa fa-times',
        'label' => Yii::t('games', 'My play games'),
        'options' => [
            'class' => 'btn btn-primary',
        ],
    ],
    [
        'url' => Url::to(['/games/saper/remove-all']),
        'icon' => 'fa fa-trash',
        'label' => Yii::t('games', 'Remove games'),
        'options' => [
            'class' => 'btn btn-danger',
            'data-request' => 'ajax',
        ],
    ],
];

$btns = [];
foreach ($buttons as $btn) {
    $btns[] = Html::a($btn['label'], $btn['url'], $btn['options']);
}
?>

<?= \yii\bootstrap\ButtonGroup::widget([
    'buttons' => $btns,
    'options' => [
        'class' => 'btn-group btn-group-sm m-b-1',
    ],
])?>
