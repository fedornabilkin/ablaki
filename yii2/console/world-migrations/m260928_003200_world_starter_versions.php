<?php
use common\modules\world\support\WorldDomainMigration;

/** Existing one-time claims remain authoritative; never reconstruct unknown historical terms. */
class m260928_003200_world_starter_versions extends WorldDomainMigration
{
    public function safeUp()
    {
        $this->domain('world_starter_definition', ['code' => $this->string(80)->notNull(), 'name' => $this->string(120)->notNull()], [], [['code']]);
        $this->domain('world_starter_revision', ['version' => $this->integer()->notNull(), 'status' => $this->string(16)->notNull(), 'requirements_json' => $this->text()->notNull(), 'published_at' => $this->integer()], ['definition_id' => ['world_starter_definition'], 'author_user_id' => ['user', 'id', false]], [['definition_id', 'version']]);
        $this->domain('world_starter_item', ['quantity' => $this->integer()->notNull()], ['revision_id' => ['world_starter_revision'], 'item_id' => ['craft_item']], [], [], ['revision_id', 'item_id']);
        $this->domain('world_starter_current', [], ['definition_id' => ['world_starter_definition'], 'revision_id' => ['world_starter_revision']], [['revision_id']], [], ['definition_id']);
        if (!$this->db->schema->getTableSchema('world_starter_grant', true)->getColumn('grant_revision_id')) $this->addColumn('world_starter_grant', 'grant_revision_id', $this->reference('world_starter_revision') . ' NULL');
        $this->foreign('fk_world_starter_claim_revision', 'world_starter_grant', 'grant_revision_id', 'world_starter_revision');
    }
}
