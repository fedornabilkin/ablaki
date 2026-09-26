<?php
namespace console\controllers;

use Yii;

/** Apply the additive privacy migration without running unrelated historical migrations. */
class ForumSetupController extends \yii\console\Controller
{
    public function actionInstall(): int
    {
        if (!in_array(getenv('FORUM_INSTALL'), ['confirmed-test-checkout', 'confirmed-production-checkout'], true)) throw new \RuntimeException('Explicit forum deployment context required.');
        $file = 'm260926_110000_forum_privacy.php';
        $directory = sys_get_temp_dir().'/ablaki-forum-migration-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot prepare forum migration.');
        try {
            if (!copy(Yii::getAlias('@console/migrations/'.$file), $directory.'/'.$file)) throw new \RuntimeException('Cannot copy forum migration.');
            $controller = new \yii\console\controllers\MigrateController('forum-migration', Yii::$app, ['migrationPath'=>$directory, 'interactive'=>false]);
            $exit = $controller->runAction('up');
            if ($exit !== 0) throw new \RuntimeException('Forum migration failed.');
        } finally {
            if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            rmdir($directory);
        }
        Yii::$app->db->schema->refresh();
        return 0;
    }
}
