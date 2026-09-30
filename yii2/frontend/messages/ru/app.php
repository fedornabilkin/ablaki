<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 11.04.2018
 * Time: 22:44
 */

$arr = require Yii::getAlias('@common') . '/messages/ru/app.php';

$arr['User wall {attr}'] = 'Стена пользователя {attr}';
$arr['Registration date'] = 'Дата регистрации';
$arr['Last activity'] = 'Последняя активность';
$arr['Collected bonuses: {attr}'] = 'Собрано бонусов: {attr}';
$arr['The link to this page is referral'] = 'Ссылка на эту страницу является реферальной';

return $arr;