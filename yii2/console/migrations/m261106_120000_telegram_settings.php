<?php
use yii\db\Migration;

final class m261106_120000_telegram_settings extends Migration
{
    public function up()
    {
        if (!$this->db->schema->getTableSchema('{{%auth_provider_settings}}', true)) {
            $this->createTable('{{%auth_provider_settings}}', [
                'provider' => $this->string(32)->notNull()->append('PRIMARY KEY'),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'bot_id' => $this->string(16)->null(),
                'bot_username' => $this->string(32)->notNull()->defaultValue(''),
                'updated_at' => $this->integer()->notNull(),
            ], $this->db->driverName === 'mysql' ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : null);
        }
        if (!(new \yii\db\Query())->from('{{%auth_provider_settings}}')->where(['provider' => 'telegram'])->exists($this->db)) {
            $this->insert('{{%auth_provider_settings}}', ['provider' => 'telegram', 'enabled' => true, 'updated_at' => time()]);
        }
    }

    public function down() { echo "Provider settings are retained. Disable Telegram in the administration panel.\n"; return false; }
}
