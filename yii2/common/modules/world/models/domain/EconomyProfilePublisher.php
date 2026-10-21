<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CraftCatalog;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Explicit preview/publication; installs no money, claims, orders or gameplay flags. */
final class EconomyProfilePublisher
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function catalogPatch(array $profile, array $before): array
    {
        $patch = ['version' => 1, 'categories' => [], 'items' => [], 'stations' => [], 'recipes' => []];
        $items = array_column($before['items'], null, 'code'); $recipes = array_column($before['recipes'], null, 'code');
        if (!$before['categories']) throw new GameError('CATALOG_NOT_READY', 'Сначала установите каталог крафта.', 503);
        $category = $before['categories'][0]['code']; $stations = [];
        foreach ($before['stations'] as $station) if ($station['item'] !== null) $stations[$station['item']] = $station['code'];
        foreach ($profile['items'] as $code => $row) {
            $old = $items[$code] ?? ['code' => $code, 'name' => $row['name'], 'label' => $row['name'], 'description' => '', 'category' => $category,
                'kind' => $row['kind'], 'rarity' => 'common', 'icon' => isset($row['restore_health']) ? 'utensils' : 'hammer',
                'storage_kind' => 'none', 'stack_size' => $row['stack_size'], 'destroyable' => 1, 'use_xp' => 0, 'gather_quantity' => 0, 'active' => 1];
            // Supply quantities belong to the versioned grant, not every item with a gather flag.
            $old['gather_quantity'] = 0; $patch['items'][] = $old;
        }
        foreach ($profile['recipes'] as $code => $row) {
            $recipe = $recipes[$code] ?? ['code' => $code, 'name' => 'Создать: ' . $profile['items'][$row['output']]['name'],
                'description' => '', 'category' => $category, 'active' => 1];
            if ($row['station'] !== null && !isset($stations[$row['station']])) throw new GameError('STATION_NOT_READY', 'Каталог станции не установлен: ' . $row['station'], 503);
            $fee = Money::parse($row['fee_cr'])->decimal();
            if (substr($fee, -4) !== '0000') throw new GameError('CRAFT_FEE_SCALE_UNSUPPORTED', 'Текущий каталог поддерживает целую фиксированную плату крафта.', 422);
            $recipe = array_merge($recipe, ['output' => $row['output'], 'output_quantity' => $row['quantity'], 'cost_credits' => (int)$fee,
                'experience' => $row['experience'], 'min_level' => $row['min_level'], 'station' => $row['station'] === null ? null : $stations[$row['station']],
                'tools' => $row['tools'], 'requires' => $row['requires'], 'ingredients' => []]);
            foreach ($row['ingredients'] as $item => $quantity) $recipe['ingredients'][] = ['item' => $item, 'quantity' => $quantity];
            $patch['recipes'][] = $recipe;
        }
        return $patch;
    }
    public function preview(array $profile): array
    {
        EconomyProfile::validate($profile);
        if ($profile['storage']['backpack_slots'] !== 20) throw new GameError('BACKPACK_CONTRACT_UNSUPPORTED', 'Начальные 20 ячеек рюкзака и прежние купленные права сохраняются.', 422);
        foreach ($profile['actions'] as $action) if ($action['quota'] === 'extraction_daily' && ($action['ingredients'] || !Money::parse($action['fee_cr'])->isZero()))
            throw new GameError('SOURCE_CONTRACT_UNSUPPORTED', 'Для этого выпуска источники используют бесплатный труд и не расходуют сырьё.', 422);
        if (!$this->db->schema->getTableSchema('world_economy_profile')) throw new GameError('SCHEMA_NOT_READY', 'Сначала установите схему профилей.', 503);
        $catalog = new CraftCatalog(new CraftStorage($this->db)); $before = $catalog->export();
        $patch = $this->catalogPatch($profile, $before); $catalogPreview = $catalog->preview($patch);
        $templates = (new Query())->from('world_template')->where(['code' => array_keys($profile['objects'])])->orderBy('id')->all($this->db);
        $crops = (new Query())->from('world_crop_revision')->orderBy('id')->all($this->db);
        $current = EconomyRuntime::current($this->db);
        $meta = (new Query())->select(['charge_credits', 'chest_slots', 'revision'])->from('craft_meta')->where(['id' => 1])->one($this->db);
        $digest = hash('sha256', CanonicalJson::encode([$profile, $before, $meta, $templates, $crops, $current ? $current['id'] : null]));
        return ['digest' => $digest, 'profile_digest' => hash('sha256', CanonicalJson::encode($profile)),
            'catalog_digest' => $catalogPreview['digest'], 'counts' => $catalogPreview['counts'], 'patch' => $patch,
            'before' => ['catalog' => $before, 'meta' => $meta, 'templates' => $templates, 'crops' => $crops], 'current_version' => $current ? $current['version'] : null,
            'already_current' => $current !== null && $current['digest'] === hash('sha256', CanonicalJson::encode($profile)),
            'applied' => false, 'money_created_cr' => '0.0000', 'existing_operations' => 'preserved'];
    }
    public function publish(array $profile, string $digest, int $author, string $reason): array
    {
        if ($author < 1 || trim($reason) === '' || mb_strlen($reason) > 500) throw new \InvalidArgumentException('Publication needs an author and reason.');
        return $this->db->transaction(function () use ($profile, $digest, $author, $reason) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $preview = $this->preview($profile);
            if (!hash_equals($preview['digest'], $digest)) throw new GameError('PROFILE_CHANGED', 'Каталог или профиль изменился. Повторите предварительную проверку.', 409);
            if ($preview['already_current']) return ['version' => $preview['current_version'], 'changed' => false];
            (new CraftCatalog(new CraftStorage($this->db)))->apply($preview['patch'], $preview['catalog_digest']);
            $this->db->createCommand()->update('craft_meta', ['charge_credits' => 1, 'chest_slots' => $profile['storage']['chest_slots'],
                'charges_updated_by' => $author, 'charges_updated_at' => time()], ['id' => 1])->execute();
            $version = 1 + (int)(new Query())->from('world_economy_profile')->max('version', $this->db);
            $this->db->createCommand()->insert('world_economy_profile', ['code' => $profile['code'], 'version' => $version,
                'config_json' => CanonicalJson::encode($profile), 'catalog_before_json' => CanonicalJson::encode($preview['before']),
                'digest' => $preview['profile_digest'], 'author_user_id' => $author, 'reason' => trim($reason), 'created_at' => time()])->execute();
            $id = (int)$this->db->getLastInsertID();
            foreach ($profile['objects'] as $code => $object) {
                $old = (new Query())->from('world_template')->where(['code' => $code])->one($this->db);
                $defaults = $old ? (json_decode($old['defaults_json'], true) ?: []) : [];
                $defaults['economy_profile_id'] = $id; $defaults['mandatory_price_cr'] = $object['price_cr'];
                if (isset($object['description'])) $defaults['description'] = $object['description'];
                $defaults['build_tools'] = []; $defaults['build_stations'] = [];
                foreach ($profile['actions'] as $action) if ($action['quota'] === 'extraction_daily' && $action['node'] === $code) $defaults['production_enabled'] = true;
                if (isset($object['exposure_class'])) $defaults['exposure_class'] = $object['exposure_class'];
                if (isset($object['storage_slots'])) $defaults['storage_capacity'] = $object['storage_slots'];
                foreach (['grow_duration_bps', 'event_protection'] as $field) if (isset($object[$field])) $defaults[$field] = $object[$field];
                if ($code === 'starter-site') $defaults['plot_kind'] = 'campsite';
                if ($code === 'garden') $defaults['plot_kind'] = 'garden';
                if ($code === 'field-kitchen') $defaults['field_kitchen'] = true;
                $defaults['map_width'] = 5; $defaults['map_height'] = 5;
                $values = ['build_seconds' => $object['work_seconds'], 'materials_json' => CanonicalJson::encode($object['materials']), 'defaults_json' => CanonicalJson::encode($defaults)];
                if ($old) $this->db->createCommand()->update('world_template', $values, ['id' => $old['id']])->execute();
                else {
                    $kind = $code === 'garden-bed' ? 'BED' : (in_array($code, ['forest-plot', 'stone-plot', 'clay-plot', 'sand-plot', 'meadow-plot', 'water-source'], true) ? 'PLOT' : 'BUILDING');
                    $this->db->createCommand()->insert('world_template', $values + ['code' => $code, 'kind' => $kind, 'name' => $object['name'],
                        'hierarchy_level' => $kind === 'BED' ? 6 : 5, 'enabled' => 1, 'player_buildable' => 1])->execute();
                }
            }
            $crop = $profile['crop'];
            $definition = (new Query())->from('world_crop')->where(['code' => 'carrot'])->one($this->db);
            if (!$definition) throw new GameError('CROP_NOT_READY', 'Сначала установите культуру моркови.', 503);
            $itemIds = (new Query())->select(['id', 'code'])->from('craft_item')->where(['code' => [$crop['seed'], $crop['yield'], $crop['water']]])->indexBy('code')->all($this->db);
            $cropVersion = 1 + (int)(new Query())->from('world_crop_revision')->where(['crop_id' => $definition['id']])->max('version', $this->db);
            $this->db->createCommand()->update('world_crop_revision', ['status' => 'superseded'], ['crop_id' => $definition['id'], 'status' => 'published'])->execute();
            $this->db->createCommand()->insert('world_crop_revision', ['crop_id' => $definition['id'], 'version' => $cropVersion, 'status' => 'published',
                'seed_item_id' => $itemIds[$crop['seed']]['id'], 'yield_item_id' => $itemIds[$crop['yield']]['id'], 'water_item_id' => $itemIds[$crop['water']]['id'],
                'seed_quantity' => $crop['seed_quantity'], 'yield_quantity' => $crop['yield_quantity'], 'water_quantity' => $crop['water_quantity_per_action'],
                'grow_seconds' => $crop['grow_seconds'], 'water_interval_seconds' => $crop['water_interval_seconds'],
                'water_window_seconds' => $crop['water_window_seconds'], 'harvest_window_seconds' => $crop['harvest_window_seconds'],
                'author_user_id' => $author, 'published_at' => time(), 'config_json' => CanonicalJson::encode(['profile_id' => $id,
                    'fees' => ['dig' => $crop['dig_fee_cr'], 'sow' => $crop['sow_fee_cr'], 'water' => $crop['water_fee_cr'], 'harvest' => $crop['harvest_fee_cr']]])])->execute();
            if ((new Query())->from('world_economy_current')->where(['id' => 1])->exists($this->db))
                $this->db->createCommand()->update('world_economy_current', ['profile_id' => $id, 'revision' => new Expression('revision+1')], ['id' => 1])->execute();
            else $this->db->createCommand()->insert('world_economy_current', ['id' => 1, 'profile_id' => $id, 'revision' => 1])->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('content_revision+1')], ['id' => 1])->execute();
            return ['version' => $version, 'profile_id' => $id, 'changed' => true, 'money_created_cr' => '0.0000'];
        });
    }
}
