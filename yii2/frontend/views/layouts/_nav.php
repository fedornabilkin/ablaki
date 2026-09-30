<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 15.04.2018
 * Time: 14:40
 */

use yii\bootstrap\Nav;
use yii\bootstrap\NavBar;
use yii\helpers\Html;
use yii\helpers\Url;


$menuItems = [
//    ['label' => 'Home', 'url' => ['/']],
//    ['label' => Yii::t('app', 'Customers'), 'url' => Url::to(['/customer/index'])],
//    ['label' => 'About', 'url' => ['/site/about']],
//    ['label' => 'Contact', 'url' => ['/site/contact']],
];
if (Yii::$app->user->isGuest) {
    $menuItems[] = ['label' => Yii::t('app', 'Signup'), 'url' => Url::to(['/user/register'])];
    $menuItems[] = ['label' => Yii::t('app', 'Login'), 'url' => Url::to(['/user/login'])];
} else {

    $menuItems[] = ['label' => Yii::t('app', 'Advertising'), 'url' => Url::to(['/advertising/company'])];
    $menuItems[] = ['label' => Yii::t('app', 'Balance'), 'url' => Url::to(['/balance'])];
    $menuItems[] = ['label' => Yii::t('app', 'Orel'), 'url' => Url::to(['/games/orel'])];
    $menuItems[] = ['label' => Yii::t('app', 'Saper'), 'url' => Url::to(['/games/saper'])];
    $menuItems[] = [
        'label' => Yii::$app->user->identity->username,
        'url' => Url::to(['/user/wall', 'login' => Yii::$app->user->identity->username])
    ];
    $menuItems[] = '<li>'
        . Html::beginForm(['/user/logout'], 'post')
        . Html::submitButton(
            Yii::t('app', 'Logout'),
            ['class' => 'btn btn-link logout']
        )
        . Html::endForm()
        . '</li>';
}

NavBar::begin([
    'brandLabel' => Yii::$app->name,
    'brandUrl' => Yii::$app->homeUrl,
    'options' => [
        'class' => 'navbar-main navbar-inverse navbar-top',
    ],
]);

echo Nav::widget([
    'options' => ['class' => 'navbar-nav navbar-right'],
    'items' => $menuItems,
]);

NavBar::end();