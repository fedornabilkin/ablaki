<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use common\models\user\User;
use common\models\user\UserRelationInterface;
use common\modules\craft\interfaces\CraftItemRelationInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_inventory".
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $item_id
 * @property int|null $item_quantity
 * @property int|null $slot
 *
 * @property CraftItem $item
 * @property User $user
 */
class CraftInventory extends ActiveRecord implements UserRelationInterface, CraftItemRelationInterface
{
    use ModelQueryTrait;

    public const SLOT_MAX_LIMIT = 100;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_inventory';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id'], 'required'],
            [['item_id', 'item_quantity', 'slot'], 'default', 'value' => null],
            [['item_quantity'], 'default', 'value' => 0],
            [['user_id', 'item_id', 'slot'], 'integer'],
            [['item_quantity'], 'integer', 'min' => 0, 'max' => self::SLOT_MAX_LIMIT],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftItem::class, 'targetAttribute' => ['item_id' => 'id']],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('craft', 'ID'),
            'user_id' => Yii::t('craft', 'User ID'),
            'item_id' => Yii::t('craft', 'Item ID'),
            'item_quantity' => Yii::t('craft', 'Item Quantity'),
            'slot' => Yii::t('craft', 'Slot'),
        ];
    }

    public function slotMaxLimit(): int
    {
        return self::SLOT_MAX_LIMIT;
    }

    public function changeItemQuantity($quantity): void
    {
        $increment = $quantity >= 0;
        $quantity = abs($quantity);

        if ($increment) {
            $this->item_quantity += $quantity;
        } else {
            $this->item_quantity -= $quantity;
            if ($this->item_quantity < 0) {
                $this->item_quantity = 0;
                $this->item_id = null;
            }
        }
    }

    /**
     * @return ActiveQuery
     */
    public function getItem(): ActiveQuery
    {
        return $this->hasOne(CraftItem::class, ['id' => 'item_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
