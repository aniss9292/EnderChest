<?php

namespace EnderChest;

use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\ByteTag;

class EnderChestManager{

    /** @var Main */
    private $plugin;

    /** @var Config */
    private $config;

    /** default size for a fresh ender chest (single chest) */
    const SIZE_DEFAULT = 27;

    /** upgraded size (double chest) */
    const SIZE_UPGRADED = 54;

    /** price to upgrade from 27 -> 54 */
    const UPGRADE_PRICE = 500000;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        @mkdir($plugin->getDataFolder());
        $this->config = new Config($plugin->getDataFolder() . "enderchests.yml", Config::YAML, []);
    }

    /**
     * Make sure a player has a data entry, creates one with default size if missing.
     */
    public function ensurePlayer(string $uuid){
        $all = $this->config->getAll();
        if(!isset($all[$uuid])){
            $all[$uuid] = [
                "size" => self::SIZE_DEFAULT,
                "items" => []
            ];
            $this->config->setAll($all);
            $this->config->save();
        }
    }

    public function getSize(string $uuid) : int{
        $this->ensurePlayer($uuid);
        $data = $this->config->get($uuid);
        return $data["size"] ?? self::SIZE_DEFAULT;
    }

    public function isUpgraded(string $uuid) : bool{
        return $this->getSize($uuid) >= self::SIZE_UPGRADED;
    }

    public function upgrade(string $uuid){
        $this->ensurePlayer($uuid);
        $data = $this->config->get($uuid);
        $data["size"] = self::SIZE_UPGRADED;
        $this->config->set($uuid, $data);
        $this->config->save();
    }

    /**
     * Loads the player's stored ender chest items.
     *
     * @return Item[] slot => Item
     */
    public function loadItems(string $uuid) : array{
        $this->ensurePlayer($uuid);
        $data = $this->config->get($uuid);
        $rawItems = $data["items"] ?? [];
        $items = [];

        foreach($rawItems as $slot => $encoded){
            $item = $this->decodeItem($encoded);
            if($item !== null){
                $items[(int) $slot] = $item;
            }
        }

        return $items;
    }

    /**
     * Persist current contents of an inventory (array of Item, slot => Item) back to config.
     *
     * @param Item[] $contents
     */
    public function saveItems(string $uuid, array $contents){
        $this->ensurePlayer($uuid);
        $data = $this->config->get($uuid);

        $rawItems = [];
        foreach($contents as $slot => $item){
            if($item === null || $item->getId() === Item::AIR || $item->getCount() <= 0){
                continue;
            }
            $encoded = $this->encodeItem($item);
            if($encoded !== null){
                $rawItems[(string) $slot] = $encoded;
            }
        }

        $data["items"] = $rawItems;
        $this->config->set($uuid, $data);
        $this->config->save();
    }

    /**
     * Manually serializes an Item into a base64-encoded NBT byte string.
     * FIXED: Item::nbtSerialize() does NOT exist on API 2.0.0 (Genisys fork).
     * We build the NBT compound manually instead.
     *
     * FIXED (v2): the previous approach nested the item's NBT compound as
     * a child tag named "tag" inside an outer compound also holding
     * id/Damage/Count. That relies on a CompoundTag child keeping (or
     * being made to keep) the exact name "tag" both when written to bytes
     * and when read back - behavior this fork's magic property setters do
     * not reliably guarantee. Symptom: any item carrying custom NBT
     * (SmartSpawner's smartspawner_type/smartspawner_tier, EnderChest's
     * own display name, etc.) silently lost ALL of it after being stored
     * in an ender chest and reloaded, turning back into a plain vanilla
     * item (e.g. a custom "Iron Golem Spawner (Basic)" item coming back as
     * a bare Monster Spawner block with no NBT at all).
     *
     * Fix: stop nesting entirely. Write id/Damage/Count directly as
     * top-level keys INSIDE the item's own NBT compound (or a fresh one if
     * it has none), instead of wrapping the item's compound inside another
     * one under a child key. This avoids any dependency on child-tag
     * renaming - there is no nested "tag" child left to rename or
     * misplace, everything lives at the same compound level and round-trips
     * through write()/read() using only tag names that were never renamed.
     *
     * @return string|null base64-encoded little-endian NBT bytes
     */
    private function encodeItem(Item $item){
        $itemTag = $item->getNamedTag();
        if($itemTag === null || !($itemTag instanceof CompoundTag)){
            $itemTag = new CompoundTag();
        }

        // Store id/Damage/Count as sibling keys alongside whatever custom
        // NBT the item already carries (display, Lore, smartspawner_type,
        // smartspawner_tier, etc.) - all in the SAME compound, no nesting.
        // Prefixed with "__ec_" to avoid any realistic collision with a
        // plugin's own NBT keys.
        $itemTag->__ec_id     = new ShortTag("__ec_id", $item->getId());
        $itemTag->__ec_damage = new ShortTag("__ec_damage", $item->getDamage());
        $itemTag->__ec_count  = new ByteTag("__ec_count", $item->getCount());

        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->setData($itemTag);
        $bytes = $nbt->write();

        return base64_encode($bytes);
    }

    /**
     * Deserializes a base64-encoded NBT byte string back into an Item.
     * FIXED: Item::nbtDeserialize() does NOT exist on API 2.0.0 (Genisys fork).
     *
     * Reads the flat compound written by encodeItem() (v2): id/Damage/Count
     * live as top-level __ec_id/__ec_damage/__ec_count keys alongside the
     * item's own NBT (display, Lore, smartspawner_type, etc.) in the same
     * compound - no nested child tag involved.
     *
     * @return Item|null
     */
    private function decodeItem(string $encoded){
        $bytes = base64_decode($encoded, true);
        if($bytes === false){
            return null;
        }

        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->read($bytes);
        $compound = $nbt->getData();

        if(!($compound instanceof CompoundTag)){
            return null;
        }

        $id     = isset($compound->__ec_id)     ? $compound->__ec_id->getValue()     : 0;
        $damage = isset($compound->__ec_damage) ? $compound->__ec_damage->getValue() : 0;
        $count  = isset($compound->__ec_count)  ? $compound->__ec_count->getValue()  : 1;

        if($id <= 0){
            return null;
        }

        // Strip our own bookkeeping keys back out before handing the
        // compound to the item as its NBT, so they don't linger as extra
        // visible/serialized tags (e.g. leaking into anvil rename, other
        // plugins' NBT scans, etc.).
        unset($compound->__ec_id);
        unset($compound->__ec_damage);
        unset($compound->__ec_count);

        $item = Item::get($id, $damage, $count);
        $item->setNamedTag($compound);

        return $item;
    }
}