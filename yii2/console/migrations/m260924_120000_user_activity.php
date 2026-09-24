<?php
use common\services\user\UserActivity;
use yii\db\Migration;
use yii\db\Query;

class m260924_120000_user_activity extends Migration
{
    public function safeUp()
    {
        $table = $this->db->getTableSchema('{{%user}}', true);
        if (!$table) throw new RuntimeException('The user table is required.');
        if (!isset($table->columns['latest_activity'])) {
            $this->addColumn('{{%user}}', 'latest_activity', $this->dateTime()->null());
            $this->createIndex('latest_activity', '{{%user}}', 'latest_activity');
        }
        // Resume an interrupted additive migration, without rewriting existing activity.
        foreach ((new Query())->select(['id', 'created_at', 'last_login_at'])->from('{{%user}}')->where(['latest_activity' => null])->each(500, $this->db) as $user) {
            $known = max((int)$user['created_at'], (int)$user['last_login_at']);
            $date = UserActivity::date($known > 0 ? $known : time());
            $this->update('{{%user}}', ['latest_activity' => $date], ['id' => $user['id'], 'latest_activity' => null]);
        }
        if (!isset($table->columns['inactive_rating_at'])) {
            $this->addColumn('{{%user}}', 'inactive_rating_at', $this->dateTime()->null());
        }
        $this->db->getSchema()->refreshTableSchema('{{%user}}');
    }

    public function safeDown() { return false; } // Activity and penalty audit state must survive rollback.
}
