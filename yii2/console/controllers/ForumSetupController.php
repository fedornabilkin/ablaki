<?php
namespace console\controllers;

use Yii;

/** Apply forum and wall migrations without running unrelated historical migrations. */
class ForumSetupController extends \yii\console\Controller
{
    public function actionInstall(): int
    {
        if (!in_array(getenv('FORUM_INSTALL'), ['confirmed-test-checkout', 'confirmed-production-checkout'], true)) throw new \RuntimeException('Explicit forum deployment context required.');
        $files = [
            'm260926_110000_forum_privacy.php',
            'm260930_120000_forum_theme_closed.php',
            'm260930_130000_wall_description_approval.php',
        ];
        $directory = sys_get_temp_dir().'/ablaki-forum-migration-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot prepare forum migration.');
        try {
            foreach ($files as $file) {
                if (!copy(Yii::getAlias('@console/migrations/'.$file), $directory.'/'.$file)) throw new \RuntimeException('Cannot copy forum migration.');
            }
            $controller = new \yii\console\controllers\MigrateController('forum-migration', Yii::$app, ['migrationPath'=>$directory, 'interactive'=>false]);
            $exit = $controller->runAction('up');
            if ($exit !== 0) throw new \RuntimeException('Forum migration failed.');
        } finally {
            foreach ($files as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
        Yii::$app->db->schema->refresh();
        return 0;
    }
}
