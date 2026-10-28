<?php
namespace common\modules\world\modules\craft\commands;

use Yii;

/** Explicit, repeatable setup of craft migrations and the initial catalog. */
class CraftSetupController extends \yii\console\Controller
{
    public function actionInstallTest()
    {
        // This command may only be used in the known test checkout mounted by its PHP container.
        if (getenv('CRAFT_TEST_INSTALL')!=='confirmed-test-checkout') throw new \RuntimeException('Explicit test deployment context required.');
        return $this->install();
    }
    public function actionInstallProduction()
    {
        if (getenv('CRAFT_PRODUCTION_INSTALL')!=='confirmed-production-checkout') throw new \RuntimeException('Explicit production deployment context required.');
        return $this->install();
    }
    private function install()
    {
        $files=['m260920_190000_extend_classic_craft.php','m260921_100000_craft_credit_switch.php','m260924_160000_craft_storage.php','m260926_100000_craft_repair.php'];
        $directory=sys_get_temp_dir().'/ablaki-craft-migration-'.bin2hex(random_bytes(8));
        if(!mkdir($directory,0700))throw new \RuntimeException('Cannot prepare craft migrations.');
        try {
            foreach($files as $file)if(!copy(Yii::getAlias('@console/migrations/'.$file),$directory.'/'.$file))throw new \RuntimeException('Cannot prepare craft migration.');
            // Only explicitly listed craft migrations; unrelated historical migrations are not run.
            $controller=new \yii\console\controllers\MigrateController('craft-migration',Yii::$app,['migrationPath'=>$directory,'interactive'=>false]);
            $exit=$controller->runAction('up');
            if($exit!==0)throw new \RuntimeException('Craft migration failed.');
        } finally { foreach($files as $file)if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);rmdir($directory); }
        Yii::$app->db->schema->refresh();
        return $this->actionSeed();
    }
    public function actionSeed()
    {
        if (\common\modules\world\modules\craft\models\domain\CraftSeed::run(Yii::$app->db)) $this->stdout("Craft catalog additions installed.\n");
        return 0;
    }
}
