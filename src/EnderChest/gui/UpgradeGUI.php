<?php

namespace EnderChest\gui;

use EnderChest\EnderChestManager;
use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;

class UpgradeGUI extends EnderBaseGUI{

    const SLOT_INFO = 13;
    const SLOT_CONFIRM = 22;

    const CONFIRM_NAME = TextFormat::GREEN . "Confirm Upgrade";
    const UPGRADED_NAME = TextFormat::RED . "Already Upgraded";

    protected function getWindowTitle(){
        return TextFormat::LIGHT_PURPLE . "Upgrade Ender Chest";
    }

    protected function populateOnOpen(Player $who){
        $price = EnderChestManager::UPGRADE_PRICE;
        $uuid = $who->getUniqueId()->toString();
        $alreadyUpgraded = $this->plugin->getManager()->isUpgraded($uuid);

        $air = Item::get(Item::AIR, 0, 0);
        for($i = 0; $i < $this->getSize(); $i++){
            $this->setItem($i, $air);
        }

        // "Item frame" style display in the middle - shows the upgrade price
        $infoItem = Item::get(Item::EMERALD, 0, 1);
        $this->setItemDisplay($infoItem, TextFormat::LIGHT_PURPLE . "Upgrade Ender Chest", [
            TextFormat::GRAY . "Current size: " . TextFormat::WHITE . "27 " . TextFormat::GRAY . "(single chest)",
            TextFormat::GRAY . "Upgraded size: " . TextFormat::WHITE . "54 " . TextFormat::GRAY . "(double chest)",
            "",
            TextFormat::YELLOW . "Price: " . TextFormat::GREEN . "$" . number_format($price),
            "",
            $alreadyUpgraded
                ? TextFormat::RED . "You have already upgraded!"
                : TextFormat::AQUA . "Click the green wool to confirm"
        ]);
        $this->setItem(self::SLOT_INFO, $infoItem);

        // Confirm button - green wool (or red if already upgraded)
        if($alreadyUpgraded){
            $confirmItem = Item::get(Item::WOOL, 14, 1); // Red wool
            $this->setItemDisplay($confirmItem, self::UPGRADED_NAME, [
                TextFormat::GRAY . "Your ender chest is already a double chest"
            ]);
        }else{
            $confirmItem = Item::get(Item::WOOL, 5, 1); // Green wool (lime)
            $this->setItemDisplay($confirmItem, self::CONFIRM_NAME, [
                TextFormat::GRAY . "$" . number_format($price) . TextFormat::GRAY . " will be deducted from your balance"
            ]);
        }
        $this->setItem(self::SLOT_CONFIRM, $confirmItem);
    }

    /**
     * Called after a successful upgrade. Changes the confirm button to red
     * "Already Upgraded" and updates the info item WITHOUT closing the GUI.
     */
    public function markAsUpgraded(Player $who){
        $price = EnderChestManager::UPGRADE_PRICE;

        // Update info item
        $infoItem = Item::get(Item::EMERALD, 0, 1);
        $this->setItemDisplay($infoItem, TextFormat::LIGHT_PURPLE . "Upgrade Ender Chest", [
            TextFormat::GRAY . "Current size: " . TextFormat::WHITE . "54 " . TextFormat::GRAY . "(double chest)",
            TextFormat::GRAY . "Upgraded size: " . TextFormat::WHITE . "54 " . TextFormat::GRAY . "(double chest)",
            "",
            TextFormat::YELLOW . "Price: " . TextFormat::GREEN . "$" . number_format($price),
            "",
            TextFormat::GREEN . "Upgrade complete! Close this window when ready."
        ]);
        $this->setItem(self::SLOT_INFO, $infoItem);

        // Change confirm button to red "Already Upgraded"
        $confirmItem = Item::get(Item::WOOL, 14, 1); // Red wool
        $this->setItemDisplay($confirmItem, self::UPGRADED_NAME, [
            TextFormat::GRAY . "Your ender chest is now a double chest"
        ]);
        $this->setItem(self::SLOT_CONFIRM, $confirmItem);

        // Push updated contents to the client
        $this->sendContents($who);
    }
}