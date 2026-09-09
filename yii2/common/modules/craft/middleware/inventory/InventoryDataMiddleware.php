<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 19.03.2023
 * Time: 20:49
 */

namespace common\modules\craft\middleware\inventory;

use common\middleware\AbstractDataMiddleware;
use common\modules\craft\models\CraftInventory;
use common\modules\craft\models\CraftItem;

class InventoryDataMiddleware extends AbstractDataMiddleware
{
    /** @var CraftInventory */
    protected $inventory;

    /** @var CraftItem */
    protected $item;

    /** @var CraftInventory[] */
    protected $inventorySlots = [];

    public function __construct($user, $config = [])
    {
        parent::__construct($config);

        $this->user = $user;
    }

    /**
     * @return CraftInventory
     */
    public function getInventory(): CraftInventory
    {
        return $this->inventory;
    }

    /**
     * @param CraftInventory $inventory
     */
    public function setInventory(CraftInventory $inventory): void
    {
        $this->inventory = $inventory;
    }

    /**
     * @return CraftItem
     */
    public function getItem(): CraftItem
    {
        return $this->item;
    }

    /**
     * @param CraftItem $item
     */
    public function setItem(CraftItem $item): void
    {
        $this->item = $item;
    }

    /**
     * @return CraftInventory[]
     */
    public function getInventorySlots(): array
    {
        return $this->inventorySlots;
    }

    /**
     * @param CraftInventory $inventory
     */
    public function addInventorySlots(CraftInventory $inventory): void
    {
        $this->inventorySlots[] = $inventory;
    }
}
