<?php

use yii\db\Migration;

class m260930_130000_wall_description_approval extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%persone}}', 'description_approved', $this->boolean()->notNull()->defaultValue(false));
        $this->update('{{%persone}}', ['description_approved' => 1], ['and', ['not', ['description' => null]], ['<>', 'description', '']]);
    }

    public function safeDown()
    {
        $this->dropColumn('{{%persone}}', 'description_approved');
    }
}
