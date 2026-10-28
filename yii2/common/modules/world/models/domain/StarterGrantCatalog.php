<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Versioned starter content; the existing UNIQUE(user,grant_code) controls lifetime eligibility. */
class StarterGrantCatalog
{
    public static function seed(Connection $db): void
    {
        $definition = (new Query())->from('world_starter_definition')->where(['code' => ShelterCatalog::CODE])->one($db);
        if ($definition) return;
        $item = (new Query())->from('craft_item')->where(['code' => ShelterCatalog::CODE])->one($db);
        if (!$item) throw new \RuntimeException('Starter shelter item missing.');
        $db->createCommand()->insert('world_starter_definition', ['code' => ShelterCatalog::CODE, 'name' => 'Разовый шалаш'])->execute(); $definition = (int)$db->getLastInsertID();
        $db->createCommand()->insert('world_starter_revision', ['definition_id' => $definition, 'version' => 1, 'status' => 'published', 'requirements_json' => '{}', 'published_at' => time()])->execute(); $revision = (int)$db->getLastInsertID();
        $db->createCommand()->insert('world_starter_item', ['revision_id' => $revision, 'item_id' => $item['id'], 'quantity' => 1])->execute();
        $db->createCommand()->insert('world_starter_current', ['definition_id' => $definition, 'revision_id' => $revision])->execute();
    }
    public static function shelter(Connection $db, int $item): int
    {
        $revision = (new Query())->select('r.*')->from(['d' => 'world_starter_definition'])->innerJoin(['c' => 'world_starter_current'], '[[c.definition_id]]=[[d.id]]')->innerJoin(['r' => 'world_starter_revision'], '[[r.id]]=[[c.revision_id]] AND [[r.definition_id]]=[[d.id]]')->where(['d.code' => ShelterCatalog::CODE, 'r.status' => 'published'])->one($db);
        $items = $revision ? (new Query())->from('world_starter_item')->where(['revision_id' => $revision['id']])->all($db) : [];
        if (!$revision || count($items) !== 1 || (int)$items[0]['item_id'] !== $item || (int)$items[0]['quantity'] !== 1 || json_decode($revision['requirements_json'], true) !== []) throw new GameError('STARTER_GRANT_UNAVAILABLE', 'Стартовый набор ещё не подготовлен.', 503);
        return (int)$revision['id'];
    }
}
