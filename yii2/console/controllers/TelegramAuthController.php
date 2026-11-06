<?php
namespace console\controllers;

use Yii;
use yii\console\Controller;

final class TelegramAuthController extends Controller
{
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
