<?php
namespace common\modules\world\assets;

final class ControlAsset extends \yii\web\AssetBundle
{
    public $sourcePath='@common/modules/world/assets/control';
    public $css=['control.css'];
    public $depends=['yii\bootstrap\BootstrapAsset'];
    public $publishOptions=['forceCopy'=>YII_ENV_DEV];
}
