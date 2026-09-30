<?php
use common\services\game\WorldDomainMigration;
use yii\db\Expression;

/** Adds an explicit display label while retaining stable codes and legacy name fields. */
class m260930_110000_world_and_item_labels extends WorldDomainMigration
{
    public function safeUp()
    {
        foreach (['world_node' => 120, 'craft_item' => 120] as $table => $length) {
            $schema = $this->db->schema->getTableSchema($table, true);
            if (!$schema) continue;
            if (!isset($schema->columns['label'])) $this->addColumn($table, 'label', $this->string($length));
            $this->db->createCommand()->update($table, ['label' => new Expression('[[name]]')], ['or', ['label' => null], ['label' => '']])->execute();
        }
    }
}
