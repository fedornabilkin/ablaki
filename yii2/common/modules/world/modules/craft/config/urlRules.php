<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 09.01.2022
 * Time: 17:41
 */

use yii\rest\UrlRule;

return [
    ['class'=>UrlRule::class,'controller'=>['v1/craft'],'pluralize'=>false,'only'=>['index','command','history','options'],
        'extraPatterns'=>['POST command'=>'command','GET history'=>'history','OPTIONS command'=>'options','OPTIONS history'=>'options']],
];
