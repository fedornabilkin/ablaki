<?php
// Form contracts only; no application DB, game writes or live configuration.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');

use common\modules\world\models\{BuildForm, BuildSettingsForm};
use common\modules\world\support\GameError;

foreach ([BuildForm::class, BuildSettingsForm::class] as $class) {
    foreach (['Я', 'a', '🌲'] as $character) {
        $form = new $class();
        $form->setAttributes(['name' => '  ' . str_repeat($character, 24) . '  ', 'labor_budget' => '2.1234', 'parent_id' => 1, 'template_id' => 1]);
        $input = $form->input();
        if ($input['name'] !== str_repeat($character, 24) || $input['labor_budget'] !== '2.1234') throw new RuntimeException('Valid Unicode name or exact budget changed.');
        $form->name = str_repeat($character, 25);
        try { $form->input(); throw new RuntimeException('Overlong name accepted.'); }
        catch (GameError $error) {
            if ($error->status !== 422 || empty($error->details['name'])) throw new RuntimeException('Wrong name validation response.');
        }
    }
    echo 'PASS ' . $class . ': Unicode limit, trimming and exact decimal budget' . PHP_EOL;
}
echo 'World build input checks passed.' . PHP_EOL;
