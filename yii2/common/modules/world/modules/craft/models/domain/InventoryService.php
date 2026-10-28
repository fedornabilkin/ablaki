<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 15:18
 */

namespace common\modules\world\modules\craft\models\domain;


use common\models\user\Person;
use common\modules\world\modules\craft\middleware\inventory\ChangeQuantityMiddleware;
use common\modules\world\modules\craft\middleware\inventory\CheckFreeSlotMiddleware;
use common\modules\world\modules\craft\middleware\inventory\InventoryDataMiddleware;
use common\modules\world\modules\craft\middleware\inventory\MaxItemSlotMiddleware;
use common\modules\world\modules\craft\middleware\inventory\PrepareSlotMiddleware;
use common\modules\world\modules\craft\models\CraftInventory;
use common\modules\world\modules\craft\models\CraftItem;
use Exception;
use Yii;
use yii\base\InvalidConfigException;

class InventoryService
{
    public function addItem(Person $person, CraftItem $item): void
    {
        $this->moveItem($person, $item);
    }

    public function removeItem(Person $person, CraftItem $item): void
    {
        $item->setQuantity(0 - $item->getQuantity());
        $this->moveItem($person, $item);
    }

    public function moveItem(Person $person, CraftItem $item): void
    {
        Yii::$app->db->transaction(function() use($person,$item) {
            $storage=new CraftStorage(Yii::$app->db);
            $storage->lock('craft_meta',['id'=>1]);
            StorageMaintenance::writable(Yii::$app->db);
            if ($storage->isCanonical()) (new \common\modules\world\support\Locks(Yii::$app->db))->owners([(int)$person->user_id]);
            $storage->lock('persone',['user_id'=>$person->user_id]);
            if ($storage->isCanonical()) $storage->lock('world_registry',['id'=>1]);
            $storage->move((int)$person->user_id,$item->getAttributes(),$item->getQuantity());
        });
    }

    /**
     * @param Person $person
     * @param ...$itemIds
     * @return CraftInventory[]
     * @throws InvalidConfigException
     */
    public function availableItems(Person $person, ...$itemIds): array
    {
        $query = CraftInventory::find()
            ->andWhere(['user_id' => $person->user_id])
            ->andFilterWhere(['item_id' => $itemIds]);
        $storage = new CraftStorage(Yii::$app->db);
        if ($storage->isCanonical()) {
            $backpack = (new CanonicalInventory($storage))->backpack((int)$person->user_id);
            if (!$backpack) return [];
            $active = (new \common\modules\world\modules\craft\models\domain\CraftInventory($storage))->capacity((int)$person->user_id)['active_slots'];
            $query->andWhere(['storage_id' => $backpack['id']])->andWhere(['between', 'slot', 1, $active])->andWhere(['>', 'item_quantity', 0]);
        }
        return $query->all();
    }

    public function deficitItem(CraftInventory $available, CraftItem $required): bool
    {
        return ($available->getQuantity() - $required->getQuantity()) < 0;
    }
}
