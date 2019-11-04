<?php

namespace frontend\assets;

use common\assets\AbstractAsset;
use yii\bootstrap\BootstrapAsset;
use yii\web\YiiAsset;

/**
 * Main frontend application asset bundle.
 */
class AppAsset extends AbstractAsset
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'css/site.css',
    ];
    public $js = [
    ];
    public $depends = [
        YiiAsset::class,
        BootstrapAsset::class,
        AwesomeAsset::class,
    ];
}
