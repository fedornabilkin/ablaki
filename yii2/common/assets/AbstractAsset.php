<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 26.05.2018
 * Time: 16:56
 */

namespace common\assets;


use yii\web\AssetBundle;

class AbstractAsset extends AssetBundle
{
    // всегда публиковать актуальные версии в режиме DEV
    public $publishOptions = [
        'forceCopy' => YII_ENV_DEV ? true : false,
    ];
}