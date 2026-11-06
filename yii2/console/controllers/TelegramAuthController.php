<?php
namespace console\controllers;

use Yii;
use yii\console\Controller;

final class TelegramAuthController extends Controller
{
    /** Install only Telegram migrations; unrelated legacy migrations are never replayed. */
    public function actionInstall(): int
    {
        if (!in_array(getenv('TELEGRAM_AUTH_INSTALL'), ['confirmed-test-checkout', 'confirmed-production-checkout'], true)) {
            throw new \RuntimeException('Explicit Telegram deployment context required.');
        }
        $files = [
            'm261104_120000_telegram_auth.php',
            'm261106_120000_telegram_settings.php',
            'm261106_140000_telegram_unlink.php',
        ];
        $directory = sys_get_temp_dir() . '/ablaki-telegram-migrations-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot prepare Telegram migrations.');
        try {
            foreach ($files as $file) {
                if (!copy(Yii::getAlias('@console/migrations/' . $file), $directory . '/' . $file)) {
                    throw new \RuntimeException('Cannot copy Telegram migration.');
                }
            }
            $controller = new \yii\console\controllers\MigrateController('telegram-migration', Yii::$app, [
                'migrationPath' => $directory, 'interactive' => false,
            ]);
            if ($controller->runAction('up') !== 0) throw new \RuntimeException('Telegram migration failed.');
        } finally {
            foreach ($files as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
            rmdir($directory);
        }
        Yii::$app->db->schema->refresh();
        $this->stdout("Telegram migrations applied. Existing accounts and provider settings preserved.\n");
        return 0;
    }

    /** Run hourly. Keep no request/proof hashes after their recovery window; audit retention is 30 days. */
    public function actionCleanup(): int
    {
        $db = Yii::$app->db;
        foreach ([
            ['{{%telegram_auth_request}}', 'expires_at', time() - 120],
            ['{{%auth_rate_limit}}', 'expires_at', time()],
            ['{{%auth_audit}}', 'created_at', time() - 30 * 86400],
        ] as [$table, $column, $before]) {
            if ($db->schema->getTableSchema($table)) $db->createCommand()->delete($table, ['<', $column, $before])->execute();
        }
        $this->stdout("Expired Telegram authentication records removed.\n");
        return 0;
    }
}
