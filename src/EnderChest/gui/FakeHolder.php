<?php

namespace EnderChest\gui;

use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryHolder;
use pocketmine\math\Vector3;

class FakeHolder extends Vector3 implements InventoryHolder{

    private $inventory;

    public function __construct($x, $y, $z){
        parent::__construct($x, $y, $z);
    }

    public function setInventory(Inventory $inv){
        $this->inventory = $inv;
    }

    public function getInventory(){
        return $this->inventory;
    }
}
