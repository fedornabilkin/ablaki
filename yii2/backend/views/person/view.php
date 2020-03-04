<?php

use common\models\HistoryBalance;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\web\YiiAsset;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $model common\models\user\Person */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'People'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
YiiAsset::register($this);
?>
<div class="person-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            'id',
            'user_id',
            'balance',
            'balance_in',
            'balance_out',
            'credit',
            'refovod',
            'rating',
            'referrer:ntext',
            'bonus_count',
            'autoriz',
        ],

    ]) ?>

</div>

<? echo $this->render('@app/common/views/history-balance/list.php', ['dataProvider' => $dataProvider, 'searchModel' => $searchModel]) ?>

