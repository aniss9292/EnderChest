<?php

namespace EnderChest\gui;

use EnderChest\Main;
use EnderChest\EnderChestItemFactory;
use pocketmine\Player;

class PersonalEnderChestGUI extends EnderBaseGUI{

    protected function getWindowTitle(){
        return EnderChestItemFactory::DISPLAY_NAME;
    }

    protected function populateOnOpen(Player $who){
        $uuid = $who->getUniqueId()->toString();
        $manager = $this->plugin->getManager();
        $items = $manager->loadItems($uuid);

        foreach($items as $slot => $item){
            if($slot < $this->getSize()){
                $this->setItem($slot, $item);
            }
        }
    }
}
