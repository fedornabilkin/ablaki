<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 16.06.2018
 * Time: 13:43
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
    'columns' => [
        [
            'class' => \common\utilities\widgets\gridview\UserGamerColumn::class,
            'label' => Yii::t('games', 'Players'),
            'isWin' => function($model){
//                return $model->etap == 0;
                return $model->isWin();
            },
        ],
        [
            'label' => Yii::t('games', 'Created'),
            'format' => 'raw',
            'value' => function($data){
                $login = $data->user->username;
                $url = Url::to(['/user/wall/', 'login' => $login]);

                $winner = ['user' => '', 'gamer' => ''];
                $icon = '<span class="fa fa-star text-warning"></span>';
                if($data->etap == 0){
                    $winner['gamer'] = $icon;
                }else{
                    $winner['user'] = $icon;
                }

                $gamer = $data->userGamer->username;
                $gamerUrl = Url::to(['/user/wall/', 'login' => $gamer]);
                $userLink = $winner['user'] .' '. Html::a($login, $url);
                $gamerLink = Html::a($gamer, $gamerUrl) .' '. $winner['gamer'];
                $span = $userLink .' vs '. $gamerLink;
                return Html::tag('div', $span,['class' => 'text-center']);
            }
        ],
        [
            'label' => Yii::t('app', 'Kon'),
            'format' => 'raw',
            'value' => function($data){
                $icon = '<span class="fa fa-eye text-primary" title="'.Yii::t('app', 'Look').'"></span>';
                return $icon . ' ' . $data->kon;
            }
        ],
        [
            'class' => \common\utilities\widgets\gridview\TestColumn::class,
            'label' => 'Test',
        ],
        'created_at:date',
        'time_over_at:datetime',
    ],
]); ?>