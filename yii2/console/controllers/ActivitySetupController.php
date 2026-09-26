<?php
namespace console\controllers;

use Yii;

/** Apply only the additive user activity migration, preserving the normal Yii migration history. */
class ActivitySetupController extends \yii\console\Controller
{
    public function actionInstall(): int
    {
        if (!in_array(getenv('USER_ACTIVITY_INSTALL'), ['confirmed-test-checkout', 'confirmed-production-checkout'], true)) {
            throw new \RuntimeException('Explicit activity deployment context required.');
        }
        $file = 'm260924_120000_user_activity.php';
        $directory = sys_get_temp_dir() . '/ablaki-activity-migration-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot prepare activity migration.');
        try {
            if (!copy(Yii::getAlias('@console/migrations/' . $file), $directory . '/' . $file)) throw new \RuntimeException('Cannot copy activity migration.');
            $controller = new \yii\console\controllers\MigrateController('activity-migration', Yii::$app, ['migrationPath' => $directory, 'interactive' => false]);
            $exit = $controller->runAction('up');
            if ($exit !== 0) throw new \RuntimeException('Activity migration failed.');
        } finally {
            if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
            rmdir($directory);
        }
        Yii::$app->db->schema->refresh();
        return 0;
    }
}
