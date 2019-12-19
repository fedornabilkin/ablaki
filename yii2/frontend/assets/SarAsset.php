<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 15.06.2018
 * Time: 21:05
 */

namespace frontend\assets;


use common\assets\AbstractAsset;

class SarAsset extends AbstractAsset
{
    public $sourcePath = '@bower';

    public $css = [];
    public $js = [
        'simple-ajax-requests/dist/sar.js'
    ];

    public $depends = [
        AppAsset::class,
    ];
}