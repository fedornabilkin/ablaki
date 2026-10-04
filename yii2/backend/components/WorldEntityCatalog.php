<?php
namespace backend\components;

use common\modules\world\model\WorldNodeForm;

/** Explicit allowlist: neither a URL nor a database table name grants access to arbitrary data. */
class WorldEntityCatalog
{
    public static function definitions(): array
    {
        $groups = [
            'Каталоги и имущество' => [
                'world_premises_offer' => 'Предложения построек', 'world_garden_offer' => 'Предложения огородов',
                'world_template' => 'Шаблоны объектов', 'world_template_revision' => 'Версии шаблонов',
                'world_membership' => 'Участники мира', 'craft_storage' => 'Хранилища', 'craft_inventory' => 'Предметы в хранилищах',
                'world_map_cell' => 'Ячейки карты', 'world_premises_purchase' => 'Покупки построек', 'world_garden_purchase' => 'Покупки огородов',
                'world_expansion_policy' => 'Условия расширения', 'world_expansion_entitlement' => 'Расширения участков',
                'world_equipment_expansion' => 'Условия мест оборудования', 'world_slot_entitlement' => 'Купленные места оборудования',
                'world_capacity_place' => 'Места вместимости', 'world_capacity_entitlement' => 'Купленная вместимость',
                'world_room_extension' => 'Расширения комнат',
            ],
            'Выращивание' => [
                'world_crop_revision' => 'Версии культур', 'world_crop_cycle' => 'Посевы', 'world_crop_action' => 'Уход за посевами',
                'world_harvest_result' => 'Сборы урожая', 'world_harvest_lot' => 'Партии урожая',
            ],
            'Строительство и жильё' => [
                'world_construction' => 'Строительство', 'world_construction_site' => 'Строительные площадки',
                'world_repair_offer' => 'Предложения ремонта', 'world_repair_contract' => 'Договоры ремонта',
                'world_building_demolition' => 'Сносы построек', 'world_forced_demolition' => 'Принудительные сносы',
                'world_demolition_recovery' => 'Возвращённое имущество',
                'world_shelter_deployment' => 'Установленные укрытия', 'world_shelter_protection' => 'Защита укрытий', 'world_shelter_repair' => 'Ремонты укрытий',
                'world_lodging_interval' => 'Проживание в укрытиях', 'world_housing_place' => 'Места ночлега',
                'world_housing_interval' => 'Проживание в домах', 'world_housing_extension' => 'Расширения жилья',
                'world_housing_night_claim' => 'Ночёвки в домах', 'world_night_policy' => 'Правила ночей',
                'world_night_period' => 'Ночи', 'world_night_resolution' => 'Результаты ночёвок',
            ],
            'Население и события' => [
                'world_job_position' => 'Рабочие места', 'npc' => 'NPC', 'npc_assignment' => 'Назначения NPC',
                'npc_hire_offer' => 'Предложения найма', 'npc_hire_receipt' => 'История найма',
                'npc_training_program' => 'Программы обучения', 'npc_training' => 'Обучение NPC', 'npc_training_result' => 'Результаты обучения',
                'production_order' => 'Производственные заказы', 'quest_instance' => 'Задания мира',
                'world_event' => 'События', 'world_event_effect' => 'Эффекты событий', 'world_event_action' => 'Действия событий',
                'world_event_application' => 'Применения событий', 'world_event_actor_effect' => 'Эффекты на участников',
                'world_population_snapshot' => 'Снимки населения',
            ],
            'Финансовые связи' => [
                'economy_subject' => 'Владельцы счетов', 'economy_account' => 'Счета объектов',
                'economy_purchase_order' => 'Заказы на закупку', 'economy_order_fulfillment' => 'Поставки по заказам',
            ],
            'Правила и история' => [
                'world_starter_definition' => 'Стартовые наборы', 'world_starter_revision' => 'Версии стартовых наборов',
                'world_starter_item' => 'Состав стартовых наборов', 'world_starter_grant' => 'Выданные стартовые вещи',
                'world_economy_policy' => 'Правила экономики', 'world_tick' => 'Экономические периоды',
                'world_tick_task' => 'Задачи периодов', 'world_tick_snapshot' => 'Снимки периодов',
                'world_period_state' => 'Состояние объектов в периодах', 'world_period_effect' => 'Эффекты периодов',
                'world_change_set' => 'Наборы изменений', 'world_change_item' => 'Состав изменений', 'world_audit' => 'Журнал изменений',
            ],
        ];
        $result = [];
        foreach ($groups as $group => $tables) foreach ($tables as $table => $label) {
            $result[$table] = ['label' => $label, 'group' => $group, 'route' => 'world-' . str_replace('_', '-', preg_replace('/^world_/', '', $table))];
        }
        foreach (['world_crop' => ['Культуры', 'world-crop'], 'world_slot' => ['Места оборудования', 'world-slot'],
            'world_warehouse_policy' => ['Склады', 'world-warehouse']] as $table => $info) {
            $result[$table] = ['label' => $info[0], 'route' => $info[1], 'group' => null];
        }
        return $result;
    }

    public static function controllers(): array
    {
        $result = [];
        foreach (self::definitions() as $table => $definition) {
            if ($definition['group'] === null) continue;
            $result[$definition['route']] = ['class' => in_array($table, ['world_premises_offer', 'world_garden_offer'], true)
                ? \backend\components\WorldOfferController::class : \backend\components\WorldRecordController::class, 'table' => $table];
        }
        return $result;
    }

    public static function menu(): array
    {
        $items = [['label' => 'Обзор и готовность', 'icon' => 'dashboard', 'url' => ['/world-admin/index']]];
        foreach (WorldNodeForm::TYPES as $type => $label) $items[] = ['label' => $label, 'icon' => 'map-o', 'url' => ['/' . WorldNodeForm::ROUTES[$type] . '/index']];
        foreach (['world_crop', 'world_warehouse_policy', 'world_slot'] as $table) {
            $definition = self::definitions()[$table];
            $items[] = ['label' => $definition['label'], 'icon' => 'th', 'url' => ['/' . $definition['route'] . '/index']];
        }
        $groups = [];
        foreach (self::definitions() as $definition) if ($definition['group'] !== null) $groups[$definition['group']][] = [
            'label' => $definition['label'], 'icon' => 'circle-o', 'url' => ['/' . $definition['route'] . '/index']];
        foreach ($groups as $label => $children) $items[] = ['label' => $label, 'icon' => 'folder-o', 'url' => '#', 'items' => $children];
        return $items;
    }

    public static function label(string $field): string
    {
        return [
            'id' => 'ID', 'name' => 'Название', 'code' => 'Код', 'kind' => 'Вид', 'status' => 'Состояние', 'state' => 'Состояние',
            'node_id' => 'Объект', 'world_id' => 'Мир', 'parent_id' => 'Родитель', 'root_id' => 'Мир', 'settlement_id' => 'Поселение',
            'plot_id' => 'Участок', 'building_id' => 'Постройка', 'room_id' => 'Комната', 'bed_id' => 'Грядка', 'garden_node_id' => 'Огород',
            'starter_site_id' => 'Стартовая стоянка', 'membership_id' => 'Участник мира', 'owner_user_id' => 'Владелец, ID', 'user_id' => 'Пользователь, ID',
            'author_user_id' => 'Автор, ID', 'actor_id' => 'Участник, ID', 'storage_id' => 'Хранилище', 'inventory_id' => 'Предмет в хранилище',
            'subject_id' => 'Владелец счёта', 'role' => 'Назначение счёта', 'amount' => 'Сумма', 'currency' => 'Валюта',
            'recipient_node_id' => 'Получатель', 'home_node_id' => 'Место проживания', 'scope_node_id' => 'Область действия',
            'position_id' => 'Рабочее место', 'program_id' => 'Программа обучения', 'assignment_id' => 'Назначение',
            'seed_item_id' => 'Семена', 'yield_item_id' => 'Урожай', 'water_item_id' => 'Вода для полива',
            'seed_quantity' => 'Семян на посадку', 'yield_quantity' => 'Полный урожай', 'grow_seconds' => 'Рост, секунд',
            'water_quantity' => 'Воды на полив', 'water_interval_seconds' => 'Интервал полива, секунд', 'water_window_seconds' => 'Время на полив, секунд',
            'harvest_window_seconds' => 'Срок сбора, секунд', 'water_missed' => 'Пропущен полив',
            'slot_type' => 'Назначение места', 'size' => 'Занимаемая площадь', 'exposure_class' => 'Защита', 'compatibility_json' => 'Совместимые предметы',
            'item_id' => 'Предмет, ID', 'item_quantity' => 'Количество', 'quantity' => 'Количество', 'slot' => 'Ячейка', 'position' => 'Номер места',
            'capacity' => 'Вместимость', 'initial_capacity' => 'Начальная вместимость', 'max_capacity' => 'Предельная вместимость',
            'price' => 'Цена, Cr', 'base_price' => 'Базовая цена, Cr', 'area' => 'Площадь', 'ordinal' => 'Порядковый номер',
            'revision' => 'Редакция', 'version' => 'Версия', 'template_id' => 'Шаблон', 'template_revision_id' => 'Версия шаблона',
            'crop_id' => 'Культура', 'crop_revision_id' => 'Версия культуры', 'cycle_id' => 'Посев', 'offer_id' => 'Предложение', 'purchase_id' => 'Покупка',
            'policy_id' => 'Условия', 'place_id' => 'Место', 'deployment_id' => 'Укрытие', 'period_id' => 'Период', 'tick_id' => 'Период экономики',
            'event_id' => 'Событие', 'definition_id' => 'Определение', 'change_set_id' => 'Набор изменений',
            'created_at' => 'Создано', 'updated_at' => 'Изменено', 'published_at' => 'Опубликовано', 'joined_at' => 'Вступил', 'grace_until' => 'Льготный период до',
            'planted_at' => 'Посажено', 'ready_at' => 'Созреет', 'water_due_at' => 'Полив до', 'paused_at' => 'Приостановлено', 'closed_at' => 'Закрыто',
            'harvested_at' => 'Собрано', 'spoiled_at' => 'Порча применена', 'operation_id' => 'Операция', 'transfer_id' => 'Платёж, ID',
            'reason' => 'Причина', 'action' => 'Действие', 'terms_json' => 'Условия', 'config_json' => 'Настройки', 'rules_json' => 'Правила',
            'before_json' => 'До изменения', 'after_json' => 'После изменения', 'result_json' => 'Результат', 'requirements_json' => 'Требования',
            'active_settlement_id' => 'Действует в поселении', 'x' => 'Координата X', 'y' => 'Координата Y',
        ][$field] ?? ucwords(str_replace('_', ' ', $field));
    }
}
