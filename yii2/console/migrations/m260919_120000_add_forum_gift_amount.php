<?php

use yii\db\Migration;

class m260919_120000_add_forum_gift_amount extends Migration
{
    public function up()
    {
        return $this->db->driverName === 'mysql' ? $this->safeUp() : parent::up();
    }

    public function safeUp()
    {
        if ($this->db->driverName === 'mysql') {
            if ($this->db->getTransaction()) throw new \RuntimeException('Run gift amount DDL outside a transaction.');
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $this->db->tablePrefix . 'forum_comment_gift'])->queryScalar();
            if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Gift backfill requires InnoDB: forum_comment_gift');
        }
        if (!$this->db->getTableSchema('{{%forum_comment_gift}}', true)->getColumn('amount')) {
            $this->addColumn('{{%forum_comment_gift}}', 'amount', $this->integer()->notNull()->defaultValue(1));
        }
        $this->db->schema->refresh();
        // Start after DDL, so an invalid legacy record rolls back the entire
        // backfill on MySQL too. A retry reuses the already added column.
        $this->db->transaction(function () {
            // Gifts made before this optional migration already have authoritative debit histories.
            $query = (new \yii\db\Query())->select(['id' => 'gift.id', 'actual' => \common\modules\forum\services\CommentGiftSchema::amountExpression($this->db, true)])
                ->from(['gift' => 'forum_comment_gift'])->orderBy('gift.id');
            foreach ($query->batch(100, $this->db) as $batch) {
                foreach ($batch as $gift) {
                    $amount = (int)$gift['actual'];
                    if ($amount < 1 || $amount > 3 || (float)$gift['actual'] !== (float)$amount) throw new \RuntimeException('Invalid recorded gift amount.');
                    if ($amount !== 1) $this->update('{{%forum_comment_gift}}', ['amount' => $amount], ['id' => $gift['id']]);
                }
            }
        });
    }

    public function safeDown()
    {
        // Preserve the financial audit, including gifts larger than one credit.
        return false;
    }
}
