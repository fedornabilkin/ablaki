<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 14.07.2018
 * Time: 12:00
 */

namespace common\modules\games\assets;


use common\assets\AbstractAsset;
use frontend\assets\MainAsset;

class OrelAsset extends AbstractAsset
{

    public $sourcePath = __DIR__;

    public $css = [];
    public $js = [
        'js/orel.js'
    ];

    public $depends = [
        MainAsset::class,
    ];
}