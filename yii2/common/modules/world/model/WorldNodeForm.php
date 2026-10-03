<?php
namespace common\modules\world\model;

use yii\base\Model;

/** Typed administrative input. Node type and calculated fields cannot be mass assigned. */
class WorldNodeForm extends Model
{
    public const TYPES = ['WORLD' => 'Миры', 'REGION' => 'Регионы', 'SETTLEMENT' => 'Поселения',
        'BUILDING' => 'Постройки', 'ROOM' => 'Комнаты', 'PLOT' => 'Участки', 'BED' => 'Грядки'];
    public const ROUTES = ['WORLD' => 'world-root', 'REGION' => 'world-region', 'SETTLEMENT' => 'world-settlement',
        'BUILDING' => 'world-building', 'ROOM' => 'world-room', 'PLOT' => 'world-plot', 'BED' => 'world-bed'];
    private $nodeType;
    public $name = '';
    public $code = '';
    public $slug = '';
    public $parent_id;
    public $owner_user_id;
    public $visibility = 'public';
    public $portable = 0;
    public $position_x = 0;
    public $position_y = 0;
    public $position = 0;
    public $footprint = '';
    public $map_width;
    public $map_height;
    public $map_origin_x = 0;
    public $map_origin_y = 0;
    public $building_kind = 'house';
    public $revision = 0;
    public $reason = '';
    public $climate = 'temperate';
    public $settlement_kind = 'village';
    public $population = 0;
    public $plot_limit = 64;
    public $level = 1;
    public $condition = 100;
    public $max_condition = 100;
    public $operational_status = 'planned';
    public $area = 1;
    public $exposure_class = 'indoor';
    public $plot_kind = 'land';
    public $fertility = 100;
    public $allow_building = 0;
    public $ordinal = 1;
    public $unlocked = 0;

    public function __construct(string $type, array $config = [])
    {
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('Unknown world node type.');
        $this->nodeType = $type;
        foreach (\common\modules\world\service\WorldLayout::defaults($type) as $key => $value) $this->$key = $value;
        parent::__construct($config);
    }
    public function type(): string { return $this->nodeType; }
    public function detailFields(): array
    {
        return [
            'WORLD' => [], 'REGION' => ['climate'], 'SETTLEMENT' => ['settlement_kind', 'population', 'plot_limit'],
            'BUILDING' => ['building_kind', 'level', 'condition', 'max_condition', 'operational_status'], 'ROOM' => ['area', 'exposure_class'],
            'PLOT' => ['plot_kind', 'area', 'fertility', 'allow_building'], 'BED' => ['ordinal', 'unlocked'],
        ][$this->nodeType];
    }
    public static function choices(): array
    {
        return ['building_kind' => ['house' => 'Жилой дом', 'forge' => 'Кузница', 'workshop' => 'Мастерская', 'workroom' => 'Рабочее помещение', 'warehouse' => 'Большой склад', 'canopy' => 'Навес'], 'visibility' => ['public' => 'Общедоступный', 'private' => 'Личный'],
            'settlement_kind' => ['city' => 'Город', 'village' => 'Деревня'],
            'operational_status' => ['planned' => 'Запланирована', 'constructing' => 'Строится', 'active' => 'Действует', 'paused' => 'Приостановлена', 'damaged' => 'Повреждена', 'destroyed' => 'Разрушена'],
            'exposure_class' => ['outdoor' => 'Улица', 'covered' => 'Под навесом', 'indoor' => 'В помещении'],
            'plot_kind' => ['land' => 'Земля', 'campsite' => 'Стоянка', 'garden' => 'Огород'],
            'allow_building' => [0 => 'Нет', 1 => 'Да'], 'unlocked' => [0 => 'Закрыта', 1 => 'Открыта']];
    }
    public function rules(): array
    {
        $rules = [
            [['name', 'code', 'slug', 'reason'], 'trim', 'skipOnArray' => true],
            [['name', 'code', 'slug', 'visibility', 'reason', 'revision'], 'required'],
            ['name', 'string', 'max' => 120], ['reason', 'string', 'max' => 255],
            [['code', 'slug'], 'match', 'pattern' => '/^[a-z0-9][a-z0-9-]{0,79}$/D'],
            [['parent_id', 'owner_user_id'], 'default', 'value' => null],
            [['parent_id', 'owner_user_id'], 'integer', 'min' => 1, 'max' => 2147483647],
            ['portable', 'in', 'range' => [0, 1]],
            [['position_x', 'position_y', 'position'], 'required'],
            [['position_x', 'position_y', 'position'], 'integer', 'min' => -1000000, 'max' => 1000000],
            [['map_width', 'map_height', 'map_origin_x', 'map_origin_y'], 'required'],
            [['map_width', 'map_height'], 'integer', 'min' => 1, 'max' => 1000],
            [['map_origin_x', 'map_origin_y'], 'integer', 'min' => -1000000, 'max' => 1000000],
            ['footprint', 'string', 'max' => 2048],
            ['footprint', 'validateFootprint'],
            ['revision', 'integer', 'min' => 0, 'max' => 2147483647],
            ['visibility', 'in', 'range' => array_keys(self::choices()['visibility'])],
        ];
        if ($this->nodeType !== 'WORLD') $rules[] = ['parent_id', 'required'];
        foreach ($this->detailFields() as $field) {
            $rules[] = [$field, 'required'];
            if (isset(self::choices()[$field])) $rules[] = [$field, 'in', 'range' => array_keys(self::choices()[$field])];
            elseif ($field === 'climate') $rules[] = [$field, 'match', 'pattern' => '/^[a-z][a-z0-9_-]{0,23}$/D'];
            else $rules[] = [$field, 'integer', 'min' => in_array($field, ['level', 'max_condition', 'area', 'ordinal'], true) ? 1 : 0,
                'max' => $field === 'ordinal' ? 10 : 1000000];
        }
        return $rules;
    }
    public function populate(array $snapshot): void
    {
        foreach (array_merge(['name', 'code', 'slug', 'parent_id', 'owner_user_id', 'visibility', 'portable', 'position_x', 'position_y', 'position', 'revision', 'map_width', 'map_height', 'map_origin_x', 'map_origin_y'], $this->detailFields()) as $field) {
            $this->$field = array_key_exists($field, $snapshot['node']) ? $snapshot['node'][$field] : ($snapshot['details'][$field] ?? $this->$field);
        }
        $this->footprint = (string)($snapshot['node']['footprint_json'] ?? '');
    }
    public function validateFootprint($attribute): void
    {
        try { \common\modules\world\service\WorldMapGeometry::normalize((string)$this->footprint, (int)$this->position_x, (int)$this->position_y); }
        catch (\common\services\game\GameError $error) { $this->addError($attribute, $error->getMessage()); }
    }
    public function payload(int $id = 0): array
    {
        $values = ['node_type' => $this->nodeType, 'name' => $this->name, 'code' => $this->code, 'slug' => $this->slug, 'visibility' => $this->visibility,
            'parent_id' => $this->parent_id === null ? null : (int)$this->parent_id,
            'owner_user_id' => $this->owner_user_id === null ? null : (int)$this->owner_user_id, 'portable' => (int)$this->portable,
            'position_x' => (int)$this->position_x, 'position_y' => (int)$this->position_y, 'position' => (int)$this->position,
            'footprint_json' => \common\modules\world\service\WorldMapGeometry::normalize((string)$this->footprint, (int)$this->position_x, (int)$this->position_y)];
        foreach (['map_width', 'map_height', 'map_origin_x', 'map_origin_y'] as $key) $values[$key] = (int)$this->$key;
        $details = [];
        foreach ($this->detailFields() as $field) $details[$field] = in_array($field, ['building_kind', 'climate', 'settlement_kind', 'operational_status', 'exposure_class', 'plot_kind'], true) ? $this->$field : (int)$this->$field;
        if ($this->nodeType === 'BED') $details['garden_node_id'] = $values['parent_id'];
        return ['id' => $id, 'revision' => (int)$this->revision, 'reason' => $this->reason, 'values' => $values, 'details' => $details];
    }
    public function attributeLabels(): array
    {
        return ['map_width' => 'Ширина карты, ячеек', 'map_height' => 'Высота карты, ячеек', 'map_origin_x' => 'Начало карты X', 'map_origin_y' => 'Начало карты Y', 'building_kind' => 'Назначение постройки', 'name' => 'Название', 'code' => 'Постоянный код', 'slug' => 'Адрес внутри родителя', 'parent_id' => 'Родительский объект, ID',
            'owner_user_id' => 'Владелец, ID пользователя', 'visibility' => 'Видимость', 'portable' => 'Можно перемещать', 'position_x' => 'Координата X', 'position_y' => 'Координата Y',
            'position' => 'Порядок', 'reason' => 'Причина изменения', 'climate' => 'Климат (код)', 'settlement_kind' => 'Тип поселения',
            'population' => 'Население', 'plot_limit' => 'Лимит участков', 'level' => 'Уровень', 'condition' => 'Прочность',
            'max_condition' => 'Максимальная прочность', 'operational_status' => 'Состояние постройки', 'area' => 'Площадь',
            'exposure_class' => 'Защита от среды', 'plot_kind' => 'Назначение участка', 'fertility' => 'Плодородие',
            'allow_building' => 'Разрешено строительство', 'ordinal' => 'Номер грядки (1–10)', 'unlocked' => 'Грядка открыта',
            'footprint' => 'Полигон на карте'];
    }
}
