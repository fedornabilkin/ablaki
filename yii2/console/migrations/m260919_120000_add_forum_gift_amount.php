<?php

use yii\db\Migration;

class m260919_120000_add_forum_gift_amount extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%forum_comment_gift}}', 'amount', $this->integer()->notNull()->defaultValue(1));
        // Gifts made before this optional migration already have authoritative debit histories.
        $query = (new \yii\db\Query())->select(['id' => 'gift.id', 'actual' => \common\modules\forum\services\CommentGiftSchema::amountExpression($this->db, true)])
            ->from(['gift' => 'forum_comment_gift'])->orderBy('gift.id');
        foreach ($query->batch(100, $this->db) as $batch) {
            foreach ($batch as $gift) {
                $amount = (int)$gift['actual'];
                if ($amount < 1 || $amount > 3) throw new \RuntimeException('Invalid recorded gift amount.');
                if ($amount !== 1) $this->update('{{%forum_comment_gift}}', ['amount' => $amount], ['id' => $gift['id']]);
            }
        }
    }

    public function safeDown()
    {
        // Preserve the financial audit, including gifts larger than one credit.
        return false;
    }
}
