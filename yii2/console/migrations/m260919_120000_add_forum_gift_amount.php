<?php

use yii\db\Migration;

class m260919_120000_add_forum_gift_amount extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%forum_comment_gift}}', 'amount', $this->integer()->notNull()->defaultValue(1));
    }

    public function safeDown()
    {
        // Preserve the financial audit, including gifts larger than one credit.
        return false;
    }
}
