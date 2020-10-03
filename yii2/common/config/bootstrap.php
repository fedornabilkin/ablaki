<?php

function getLocalConfig(string $path): array
{
    if (file_exists($path)) {
        return require $path;
    }
    return [];
}

Yii::setAlias('@common', dirname(__DIR__));
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
Yii::setAlias('@frontend', dirname(__DIR__, 2) . '/frontend');
Yii::setAlias('@backend', dirname(__DIR__, 2) . '/backend');
Yii::setAlias('@console', dirname(__DIR__, 2) . '/console');
