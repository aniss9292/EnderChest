<?php

namespace EnderChest\gui;

use EnderChest\Main;

use pocketmine\inventory\CustomInventory;
use pocketmine\inventory\InventoryType;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\network\protocol\ContainerOpenPacket;
use pocketmine\network\protocol\ContainerClosePacket;
use pocketmine\network\protocol\BlockEntityDataPacket;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\NBT;
use pocketmine\tile\Tile;
use pocketmine\Player;
use pocketmine\item\Item;

/**
 * Fake-block GUI window, following the same proven approach used by the
 * server's EconomyGUI plugin (verified working on MCPE 0.14.3/0.15.x):
 * fake chest blocks are sent client-side only above the player's head,
 * the container is opened a couple ticks later once the client has
 * registered the blocks, and everything is reverted on close.
 */
abstract class EnderBaseGUI extends CustomInventory{

    // FIXED: Anti-dupe NBT marker, same pattern as SmartSpawner's BaseGUI.
    // Every GUI item (buttons, filler, info items) gets this marker so the
    // listener can identify and cancel any transaction involving them.
    const MENU_ITEM_NBT_KEY = "enderchest_menu_item";

    /** @var Main */
    protected $plugin;

    /** @var FakeHolder */
    protected $fakeHolder;

    /** @var Player */
    protected $ownerPlayer;

    /** @var bool whether this window renders as a double chest (54) or single (27) */
    protected $isDouble;

    /** @var int */
    protected $origBlockId = 0;
    /** @var int */
    protected $origBlockMeta = 0;
    /** @var int */
    protected $origBlockId2 = 0;
    /** @var int */
    protected $origBlockMeta2 = 0;

    /** @var int|null captured at onOpen() (tick 0), reused in finishOpen() */
    protected $capturedWindowId = null;

    abstract protected function getWindowTitle();

    abstract protected function populateOnOpen(Player $who);

    public function __construct(Main $plugin, Player $p, bool $isDouble){
        $this->plugin      = $plugin;
        $this->ownerPlayer = $p;
        $this->isDouble    = $isDouble;

        $this->fakeHolder = new FakeHolder(
            (int) $p->x,
            (int) $p->y + 2,
            (int) $p->z
        );

        $type = $isDouble ? InventoryType::get(InventoryType::DOUBLE_CHEST) : InventoryType::get(InventoryType::CHEST);
        parent::__construct($this->fakeHolder, $type);

        $this->fakeHolder->setInventory($this);
    }

    public function getOwnerPlayer(){
        return $this->ownerPlayer;
    }

    public function onOpen(Player $who){
        $this->viewers[spl_object_hash($who)] = $who;

        $this->capturedWindowId = $who->getWindowId($this);

        $x = (int) $this->fakeHolder->x;
        $y = (int) $this->fakeHolder->y;
        $z = (int) $this->fakeHolder->z;
        $x2 = $x + 1;

        $this->origBlockId    = $who->getLevel()->getBlockIdAt($x, $y, $z);
        $this->origBlockMeta  = $who->getLevel()->getBlockDataAt($x, $y, $z);
        if($this->isDouble){
            $this->origBlockId2   = $who->getLevel()->getBlockIdAt($x2, $y, $z);
            $this->origBlockMeta2 = $who->getLevel()->getBlockDataAt($x2, $y, $z);
        }

        // Air first, to force the client to register a real change even if
        // it previously cached "chest" at this exact spot.
        $this->sendBlock($who, $x, $y, $z, 0, 0);
        if($this->isDouble){
            $this->sendBlock($who, $x2, $y, $z, 0, 0);
        }

        $facing = 2; // north, fixed for both halves
        $this->sendBlock($who, $x, $y, $z, 54, $facing); // Chest

        $title = $this->getWindowTitle();

        if($this->isDouble){
            $this->sendBlock($who, $x2, $y, $z, 54, $facing);

            $this->sendChestNbt($who, $x, $y, $z, $title, $x2, $y, $z);
            $this->sendChestNbt($who, $x2, $y, $z, $title, $x, $y, $z);
        }else{
            $this->sendChestNbt($who, $x, $y, $z, $title, null, null, null);
        }

        // FIXED (single vs double chest open delay): a double chest is
        // TWO block positions the legacy client has to register (two
        // UpdateBlockPacket + two BlockEntityDataPacket pairs) before it
        // will reliably accept ContainerOpenPacket, versus one for a
        // single chest. A flat 2-tick delay was enough for the single
        // chest but was cutting it close for the double chest, especially
        // combined with the close/reopen race fixed in Main::closeActiveGui().
        // Give the double chest extra processing time (4 ticks vs 2).
        $delay = $this->isDouble ? 4 : 2;

        $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(
            new OpenContainerTask($this, $who, $x, $y, $z),
            $delay
        );
    }

    private function sendBlock(Player $who, $x, $y, $z, $id, $meta){
        $pk = new UpdateBlockPacket();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->blockId = $id;
        $pk->blockData = $meta;
        $pk->flags = 0;
        $who->dataPacket($pk);
    }

    private function sendChestNbt(Player $who, $x, $y, $z, $title, $pairX, $pairY, $pairZ){
        $tags = [
            new StringTag("id", Tile::CHEST),
            new IntTag("x", $x),
            new IntTag("y", $y),
            new IntTag("z", $z),
            new StringTag("CustomName", $title)
        ];

        if($pairX !== null){
            $tags[] = new IntTag("pairx", $pairX);
            $tags[] = new IntTag("pairy", $pairY);
            $tags[] = new IntTag("pairz", $pairZ);
        }

        $nbtData = new CompoundTag("", $tags);

        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->setData($nbtData);

        $pk = new BlockEntityDataPacket();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->namedtag = $nbt->write();
        $who->dataPacket($pk);
    }

    /**
     * Second half of onOpen, run a couple ticks after the chest blocks are
     * placed so the client has already registered them before receiving
     * the request to open the container.
     */
    public function finishOpen(Player $who, $x, $y, $z){
        if(!isset($this->viewers[spl_object_hash($who)])){
            return;
        }

        // FIXED (close/reopen race, extra guard): ReopenGuiTask already
        // defers the whole open sequence by a tick after a prior GUI's
        // close, but as a belt-and-suspenders check - in case two opens
        // ever got queued back-to-back for the same player - don't send
        // ContainerOpenPacket while Main still considers a previous
        // close "in flight". Re-check a tick later instead of just
        // silently giving up, so the open still eventually happens.
        if($this->plugin->isPendingClose($who)){
            $this->plugin->getServer()->getScheduler()->scheduleDelayedTask(
                new OpenContainerTask($this, $who, $x, $y, $z),
                1
            );
            return;
        }

        $openPk = new ContainerOpenPacket();
        $windowId = $this->capturedWindowId !== null
            ? $this->capturedWindowId
            : $who->getWindowId($this);
        $openPk->windowid = $windowId;
        $openPk->type = 0; // CHEST type, explicit
        $openPk->slots = $this->getSize();
        $openPk->x = $x;
        $openPk->y = $y;
        $openPk->z = $z;
        $who->dataPacket($openPk);

        $this->populateOnOpen($who);
        $this->sendContents($who);
    }

    public function onClose(Player $who){
        if(!isset($this->viewers[spl_object_hash($who)])){
            return;
        }

        $x = (int) $this->fakeHolder->x;
        $y = (int) $this->fakeHolder->y;
        $z = (int) $this->fakeHolder->z;
        $x2 = $x + 1;

        $this->sendBlock($who, $x, $y, $z, $this->origBlockId, $this->origBlockMeta);
        if($this->isDouble){
            $this->sendBlock($who, $x2, $y, $z, $this->origBlockId2, $this->origBlockMeta2);
        }

        $closePk = new ContainerClosePacket();
        $closePk->windowid = $this->capturedWindowId !== null
            ? $this->capturedWindowId
            : $who->getWindowId($this);
        $who->dataPacket($closePk);

        unset($this->viewers[spl_object_hash($who)]);
        $this->capturedWindowId = null;
    }

    /**
     * Sets an NBT display name on an item AND tags it as a menu item
     * (anti-dupe marker, same pattern as SmartSpawner's BaseGUI).
     */
    public function setItemDisplay(Item $item, $name = "", array $lore = []){
        $nbt = $item->getNamedTag();
        if($nbt === null){
            $nbt = new CompoundTag();
        }

        $displayTags = [];
        if($name !== ""){
            $displayTags[] = new StringTag("Name", $name);
        }
        if(!empty($lore)){
            $loreTags = [];
            foreach($lore as $line){
                $loreTags[] = new StringTag("", $line);
            }
            $displayTags[] = new ListTag("Lore", $loreTags);
        }
        if(!empty($displayTags)){
            $nbt->display = new CompoundTag("display", $displayTags);
        }

        // FIXEDUPE: Tag every GUI item so the listener can identify and
        // cancel any transaction involving it (prevents green wool from
        // dropping into player inventory on legacy MCPE clients).
        $nbt->{self::MENU_ITEM_NBT_KEY} = new ByteTag(self::MENU_ITEM_NBT_KEY, 1);

        $item->setNamedTag($nbt);
        return $item;
    }

    /**
     * Checks whether an item is a GUI menu item (has the anti-dupe marker).
     */
    public static function isMenuItem(Item $item) : bool{
        $nbt = $item->getNamedTag();
        if($nbt === null){
            return false;
        }
        return isset($nbt->{self::MENU_ITEM_NBT_KEY});
    }

    /**
     * Reads back an item's display name written via setItemDisplay(),
     * falling back to getCustomName() in case a given item type does
     * support it natively on this fork.
     */
    public static function getItemDisplayName(Item $item){
        $nbt = $item->getNamedTag();
        if($nbt !== null && isset($nbt->display) && isset($nbt->display->Name)){
            return (string) $nbt->display->Name->getValue();
        }
        return (string) $item->getCustomName();
    }
}