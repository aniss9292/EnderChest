<?php

namespace EnderChest;

use EnderChest\gui\EnderBaseGUI;
use EnderChest\gui\PersonalEnderChestGUI;
use EnderChest\gui\UpgradeGUI;

use pocketmine\event\Listener;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\utils\TextFormat;
use pocketmine\level\Position;

class EnderChestListener implements Listener{

    /** @var Main */
    private $plugin;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
    }

    /**
     * When a player places our custom Ender Chest item, register that block's
     * position in the world as an "ender chest" location.
     */
    public function onBlockPlace(BlockPlaceEvent $event){
        $item = $event->getItem();

        if(!EnderChestItemFactory::isEnderChestItem($item)){
            return;
        }

        $block = $event->getBlock();
        if($block->getId() !== Block::CHEST){
            return;
        }

        $pos = new Position($block->x, $block->y, $block->z, $block->getLevel());

        $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(
            new BlockRegisterTask($this->plugin, $this->plugin->getBlockRegistry(), $pos),
            2
        );
    }

    /**
     * If a registered ender chest block is broken, unregister it and drop the
     * custom-named item back.
     */
    public function onBlockBreak(BlockBreakEvent $event){
        $block = $event->getBlock();
        if($block->getId() !== Block::CHEST){
            return;
        }

        $pos = new Position($block->x, $block->y, $block->z, $block->getLevel());
        $registry = $this->plugin->getBlockRegistry();

        if($registry->isEnderChest($pos)){
            $registry->unregister($pos);
            $event->setDrops([EnderChestItemFactory::create(1)]);
        }
    }

    /**
     * Intercept right-click interactions with chests:
     * - normal click -> open the player's personal ender inventory
     * - sneak + click -> open upgrade GUI (only if not already upgraded)
     */
    public function onPlayerInteract(PlayerInteractEvent $event){
        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }

        $block = $event->getBlock();
        if($block->getId() !== Block::CHEST){
            return;
        }

        $pos = new Position($block->x, $block->y, $block->z, $block->getLevel());
        $registry = $this->plugin->getBlockRegistry();

        if(!$registry->isEnderChest($pos)){
            return;
        }

        $player = $event->getPlayer();
        $event->setCancelled(true);

        $manager = $this->plugin->getManager();
        $uuid = $player->getUniqueId()->toString();

        if($player->isSneaking()){
            // FIXED: Don't open upgrade GUI if already upgraded
            if($manager->isUpgraded($uuid)){
                $player->sendMessage(TextFormat::YELLOW . "This chest is already fully upgraded.");
                return;
            }
            $this->plugin->openUpgradeGui($player);
            return;
        }

        $this->plugin->openPersonalEnderChest($player);
    }

    /**
     * Handles clicks inside both our GUIs.
     *
     * FIXEDUPE: Any transaction involving a menu item (has the NBT marker)
     * is cancelled immediately, same pattern as SmartSpawner. This prevents
     * the green wool / emerald from dropping into the player's inventory on
     * legacy MCPE 0.14.3/0.15.x clients.
     */
    public function onInventoryTransaction(InventoryTransactionEvent $event){
        $queue = $event->getTransaction();

        foreach($queue->getTransactions() as $transaction){
            $inv = $transaction->getInventory();

            if(!($inv instanceof EnderBaseGUI)){
                continue;
            }

            $player = $inv->getOwnerPlayer();
            if(!($player instanceof Player)){
                continue;
            }

            $guiType = $this->plugin->getOpenGuiType($player);
            if($guiType === null){
                continue;
            }

            // FIXEDUPE: Check if the clicked item is a GUI menu item.
            // If so, cancel the transaction immediately - these items
            // are GUI furniture, never real player items.
            $slot = $transaction->getSlot();
            $clickedItem = $inv->getItem($slot);
            if(EnderBaseGUI::isMenuItem($clickedItem)){
                $event->setCancelled(true);

                if($guiType === "upgrade"){
                    $itemName = EnderBaseGUI::getItemDisplayName($clickedItem);

                    // Only process upgrade on green "Confirm Upgrade" button
                    if($slot === UpgradeGUI::SLOT_CONFIRM && $itemName === UpgradeGUI::CONFIRM_NAME){
                        $this->processUpgrade($player, $inv);
                    }
                    // Red "Already Upgraded" button or any other GUI item: just cancel
                }
                return;
            }

            // "single" - personal ender chest: allow the move, then persist
            if($guiType === "single"){
                $uuid = $player->getUniqueId()->toString();
                $manager = $this->plugin->getManager();

                $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(
                    new SaveInventoryTask($this->plugin, $manager, $inv, $uuid),
                    1
                );
                return;
            }
        }
    }

    public function onInventoryClose(InventoryCloseEvent $event){
        $inv = $event->getInventory();
        $player = $event->getPlayer();

        if(!($player instanceof Player)){
            return;
        }

        if($inv instanceof PersonalEnderChestGUI){
            $uuid = $player->getUniqueId()->toString();
            $this->plugin->getManager()->saveItems($uuid, $inv->getContents());
        }

        $this->plugin->clearOpenGuiType($player);
        $this->plugin->clearActiveGui($player);
    }

    /**
     * FIXED: After a successful upgrade, the GUI stays open.
     * The confirm button changes from green to red "Already Upgraded".
     * The player closes the GUI themselves when ready.
     */
    private function processUpgrade(Player $player, $inv){
        $manager = $this->plugin->getManager();
        $uuid = $player->getUniqueId()->toString();
        $price = EnderChestManager::UPGRADE_PRICE;

        $economy = $this->plugin->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if($economy === null){
            $player->sendMessage(TextFormat::RED . "The economy system is currently unavailable.");
            return;
        }

        $balance = $economy->myMoney($player);
        if($balance < $price){
            $player->sendMessage(TextFormat::RED . "You don't have enough money. You need $" . number_format($price));
            return;
        }

        $economy->reduceMoney($player, $price);
        $manager->upgrade($uuid);

        $player->sendMessage(TextFormat::GREEN . "Your Ender Chest has been upgraded to a Double Chest!");

        // FIXED: Don't close the GUI. Instead, update it in-place to show
        // the red "Already Upgraded" button. Player closes it themselves.
        if($inv instanceof UpgradeGUI){
            $inv->markAsUpgraded($player);
        }
    }
}