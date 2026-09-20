<?php
namespace console\controllers;

use common\modules\craft\service\CraftCatalog;
use common\modules\craft\service\CraftStorage;
use Yii;

/** Explicit CLI setup for this release; normal deployment and production startup are unchanged. */
class CraftController extends \yii\console\Controller
{
    public function actionInstallTest()
    {
        // This command may only be used in the known test checkout mounted by its PHP container.
        if (getenv('CRAFT_TEST_INSTALL')!=='confirmed-test-checkout') throw new \RuntimeException('Explicit test deployment context required.');
        $path=Yii::getAlias('@console/migrations/m260920_190000_extend_classic_craft.php');
        $directory=sys_get_temp_dir().'/ablaki-craft-migration-'.bin2hex(random_bytes(8));
        if(!mkdir($directory,0700)||!copy($path,$directory.'/'.basename($path)))throw new \RuntimeException('Cannot prepare craft migration.');
        try {
            // Yii records only this new migration; unrelated pending historical migrations are not run.
            $controller=new \yii\console\controllers\MigrateController('craft-migration',Yii::$app,['migrationPath'=>$directory,'interactive'=>false]);
            $exit=$controller->runAction('up');
            if($exit!==0)throw new \RuntimeException('Craft migration failed.');
        } finally { unlink($directory.'/'.basename($path));rmdir($directory); }
        Yii::$app->db->schema->refresh();
        return $this->actionSeed();
    }
    public function actionSeed()
    {
        $db=Yii::$app->db;
        if((new \yii\db\Query())->from('craft_recipe')->where(['code'=>'classic-plank'])->exists($db)){$this->stdout("Initial craft catalog already installed; administrator edits preserved.\n");return 0;}
        $catalog=new CraftCatalog(new CraftStorage($db));
        $data=require Yii::getAlias('@common/modules/craft/data/default-catalog.php');
        // Legacy display names can overlap; stable codes remain distinct and existing rows stay intact.
        foreach(['categories'=>'craft_category','items'=>'craft_item','recipes'=>'craft_recipe'] as $group=>$table){
            $names=array_map('trim',(new \yii\db\Query())->select('name')->from($table)->column($db));
            foreach($data[$group] as &$row)if(in_array($row['name'],$names,true))$row['name']=mb_substr($row['name'],0,39).' (крафт)';unset($row);
        }
        $preview=$catalog->preview($data);$catalog->apply($data,$preview['digest']);
        $this->stdout("Installed 44 craft items, 34 recipes and four stations.\n");return 0;
    }
}
