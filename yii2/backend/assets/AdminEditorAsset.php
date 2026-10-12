<?php
namespace backend\assets;

class AdminEditorAsset extends \yii\web\AssetBundle
{
    public $sourcePath = '@backend/assets/editor';
    public $css = ['admin-editor.css'];
    public $js = ['admin-editor.js', 'world-editor.js', 'credit-inputs.js'];
    public $depends = ['backend\assets\EntitySelectAsset', 'yii\web\YiiAsset'];
    public function init()
    {
        parent::init();
        if (YII_ENV_DEV) $this->publishOptions['forceCopy'] = true;
    }
}
