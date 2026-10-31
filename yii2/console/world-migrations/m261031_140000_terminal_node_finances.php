<?php
use common\modules\world\modules\economy\models\domain\LeafFinanceMigration;

/** Data only: preserve immutable history and transfer live balances/reserves together. */
class m261031_140000_terminal_node_finances extends \yii\db\Migration
{
    public function safeUp() { (new LeafFinanceMigration($this->db))->run('v2'); }
    public function safeDown() { return false; }
}
