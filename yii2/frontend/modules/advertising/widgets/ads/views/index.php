<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 31.07.2018
 * Time: 21:37
 *
 * @var $this yii\web\View
 * @var $item \frontend\modules\advertising\models\Advertising
 */

use yii\helpers\Html;

?>

<?=Html::a($item->title, $item->url, ['target' => '_blank'])?>