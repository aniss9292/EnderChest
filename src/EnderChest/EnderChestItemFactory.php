<?php

namespace EnderChest;

use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use pocketmine\Server;
use pocketmine\inventory\ShapedRecipe;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;

class EnderChestItemFactory{

    const DISPLAY_NAME = TextFormat::LIGHT_PURPLE . "Ender Chest";

    /**
     * Builds the custom Ender Chest item (visually a Chest block-item with a purple name).
     *
     * NOTE: Item::setCustomName() silently no-ops on ItemBlock types like
     * Chest on this fork (confirmed via EconomyGUI's item-display fix), so
     * the display name is written directly into the item's NBT display tag
     * instead, and read back the same way.
     */
    public static function create(int $count = 1) : Item{
        $item = Item::get(Item::CHEST, 0, $count);

        $nbt = $item->getNamedTag();
        if($nbt === null){
            $nbt = new CompoundTag();
        }
        $nbt->display = new CompoundTag("display", [
            new StringTag("Name", self::DISPLAY_NAME)
        ]);
        $item->setNamedTag($nbt);

        return $item;
    }

    /**
     * Checks whether a given item is our custom Ender Chest item, matched purely by
     * id + NBT display name at the moment it is used (e.g. when placed). This check
     * only matters at crafting/placement time - once a block is registered as an
     * ender chest in the world, renaming has no further effect on it.
     */
    public static function isEnderChestItem(Item $item) : bool{
        if($item->getId() !== Item::CHEST){
            return false;
        }

        $nbt = $item->getNamedTag();
        if($nbt !== null && isset($nbt->display) && isset($nbt->display->Name)){
            return (string) $nbt->display->Name->getValue() === self::DISPLAY_NAME;
        }

        // fallback in case setCustomName does work for some item variant
        return $item->hasCustomName() && $item->getCustomName() === self::DISPLAY_NAME;
    }

    /**
     * Registers the crafting recipe: Chest surrounded by 8 Obsidian (like the real Ender Chest).
     * Legacy PocketMine API: ShapedRecipe(Item $result, "row1", "row2", "row3")
     * followed by ->setIngredient(char, Item) for each shape character, then
     * registered via CraftingManager::registerRecipe().
     */
    public static function registerRecipe(){
        $result = self::create(1);

        $recipe = new ShapedRecipe(
            $result,
            "OOO",
            "OCO",
            "OOO"
        );

        $recipe->setIngredient("O", Item::get(Item::OBSIDIAN));
        $recipe->setIngredient("C", Item::get(Item::CHEST));

        Server::getInstance()->getCraftingManager()->registerRecipe($recipe);
    }
}
