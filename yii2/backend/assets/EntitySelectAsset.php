<?php
namespace backend\assets;

class EntitySelectAsset extends \yii\web\AssetBundle
{
    public $sourcePath = '@vendor/almasaeed2010/adminlte/bower_components/select2/dist';
    public $css = ['css/select2.min.css'];
    public $js = ['js/select2.full.min.js', 'js/i18n/ru.js'];
    public $depends = ['yii\web\JqueryAsset'];
}
