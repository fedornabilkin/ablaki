<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 02.08.2018
 * Time: 21:33
 */

namespace frontend\modules\advertising\assets;


use common\assets\AbstractAsset;
use frontend\assets\MainAsset;

class AdvertisingAsset extends AbstractAsset
{

    public $sourcePath = __DIR__;

    public $css = [];
    public $js = [
//        'js/orel.js'
    ];

    public $depends = [
        MainAsset::class,
    ];
}