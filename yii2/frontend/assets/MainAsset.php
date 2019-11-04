<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 02.08.2018
 * Time: 21:58
 */

namespace frontend\assets;


use common\assets\AbstractAsset;

class MainAsset extends AbstractAsset
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
    ];
    public $js = [
        'js/sar/eventSar.js',
    ];
    public $depends = [
        SarAsset::class,
    ];
}