<?php
namespace common\modules\world;

/** Flags are opt-in; schema installation never enables gameplay. */
class Module extends \yii\base\Module
{
    public $flags = [];
    public $maxDepth = 6;
    public $quoteLifetime = 300;
    public $contractVersion = 1;
    public function init()
    {
        parent::init();
        $this->controllerMap = array_merge(require __DIR__ . '/config/apiControllers.php', $this->controllerMap);
        $this->setModule('craft', ['class' => modules\craft\Module::class]);
        if (\Yii::$app instanceof \yii\web\Application) {
            \Yii::$app->urlManager->addRules(require __DIR__ . '/config/urlRules.php', false);
            $this->getModule('craft');
        }
        foreach (['world_read', 'world_write', 'storage_v2', 'economy_tick'] as $flag) {
            if (!array_key_exists($flag, $this->flags)) {
                $this->flags[$flag] = getenv(strtoupper($flag)) === '1';
            }
        }
    }
}
