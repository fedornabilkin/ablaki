<?php
use common\services\game\WorldMigration;

class m260927_190000_world_construction_site extends WorldMigration
{
    public function safeUp()
    {
        $this->table('world_construction_site', [
            'project_id' => $this->reference('world_construction') . ' NOT NULL',
            'plot_id' => $this->reference('world_node') . ' NOT NULL', 'area' => $this->integer()->notNull(),
            'commitment_id' => $this->reference('economy_spending_commitment') . ' NOT NULL',
            'storage_id' => $this->reference('craft_storage') . ' NOT NULL',
            'PRIMARY KEY ([[project_id]])',
        ]);
        $this->index('ix_construction_plot', 'world_construction_site', ['plot_id', 'project_id']);
        $this->index('ux_construction_commitment', 'world_construction_site', ['commitment_id'], true);
        $this->index('ux_construction_storage', 'world_construction_site', ['storage_id'], true);
        $this->foreign('fk_construction_site_project', 'world_construction_site', 'project_id', 'world_construction');
        $this->foreign('fk_construction_site_plot', 'world_construction_site', 'plot_id', 'world_node');
        $this->foreign('fk_construction_site_commitment', 'world_construction_site', 'commitment_id', 'economy_spending_commitment');
        $this->foreign('fk_construction_site_storage', 'world_construction_site', 'storage_id', 'craft_storage');
    }
}
