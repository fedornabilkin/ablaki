<?php
namespace common\services\auth;

use yii\db\Connection;
use yii\db\Query;

/** Public provider settings. Credentials remain in the server environment. */
final class TelegramSettings
{
    public function __construct(private readonly Connection $db) {}

    public function read(): array
    {
        if (!$this->db->schema->getTableSchema('{{%auth_provider_settings}}')) {
            return ['enabled' => false, 'bot_username' => '', 'bot_id' => null];
        }
        $row = (new Query())->from('{{%auth_provider_settings}}')->where(['provider' => 'telegram'])->one($this->db);
        return ['enabled' => (bool)($row['enabled'] ?? false),
            'bot_username' => (string)($row['bot_username'] ?? ''), 'bot_id' => $row['bot_id'] ?? null];
    }

    public function setEnabled(bool $enabled): void
    {
        $this->db->createCommand()->update('{{%auth_provider_settings}}',
            ['enabled' => (int)$enabled, 'updated_at' => time()], ['provider' => 'telegram'])->execute();
    }

    public function registerBot(string $id, string $username): void
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id) || !preg_match('/^[a-zA-Z0-9_]{2,29}bot$/iD', $username)) {
            throw new \yii\web\BadRequestHttpException('Некорректные данные бота.');
        }
        // Reconnecting a bot must never re-enable a provider disabled by an administrator.
        $this->db->createCommand()->update('{{%auth_provider_settings}}',
            ['bot_id' => $id, 'bot_username' => $username, 'updated_at' => time()], ['provider' => 'telegram'])->execute();
    }
}
