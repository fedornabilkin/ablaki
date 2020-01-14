<?php

use common\models\HistoryBalance;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\ListView;

/* @var $this yii\web\View */
/* @var $searchModel backend\models\HistoryBalanceSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'History Balances');
$this->params['breadcrumbs'][] = $this->title;
?>
<? echo $this->render('/new', ['dataProvider' => $dataProvider, 'searchModel' => $searchModel]) ?>
