<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 31.07.2018
 * Time: 21:45
 */

namespace frontend\modules\advertising\widgets\ads\asset;


use common\assets\AbstractAsset;
use frontend\assets\AppAsset;

class AdsAsset extends AbstractAsset
{
    public $sourcePath = __DIR__;

    public $css = [
//        'css/advertising.sass'
    ];
    public $js = [
//        'js/orel.js'
    ];

    public $depends = [
        AppAsset::class,
    ];
}