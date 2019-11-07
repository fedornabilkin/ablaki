<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 15.06.2018
 * Time: 21:14
 */

namespace common\modules\games\assets;


use common\assets\AbstractAsset;
use frontend\assets\MainAsset;

class SaperAsset extends AbstractAsset
{
    public $sourcePath = __DIR__;

    public $css = [];
    public $js = [
        'js/saper.js'
    ];

    public $depends = [
        MainAsset::class,
    ];
}