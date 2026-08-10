<?php

namespace EnderChest;

use EnderChest\gui\PersonalEnderChestGUI;
use EnderChest\gui\UpgradeGUI;
use EnderChest\gui\EnderBaseGUI;
use EnderChest\gui\ReopenGuiTask;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\level\Position;
use pocketmine\item\Item;

class Main extends PluginBase{

    /** @var EnderChestManager */
    private $manager;

    /** @var EnderChestBlockRegistry */
    private $blockRegistry;

    /** @var float rotates each particle tick so the ring appears to spin */
    private $particleAngleOffset = 0.0;

    /** @var string[] uuid => "single" or "upgrade", tracks which of our GUIs is open, for onInventoryTransaction/onInventoryClose routing */
    private $openGuiType = [];

    /**
     * @var \EnderChest\gui\EnderBaseGUI[] uuid => currently-open GUI instance.
     * FIXED: same pattern as EconomyGUI's $activeGUI. Without this, if the
     * player's client closed a previous EnderChest GUI without a clean
     * server-side removeWindow() (common with legacy MCPE clients on
     * abrupt/rapid close), PocketMine's internal window registration for
     * that player can remain stale. The next addWindow() call (from this
     * plugin OR from another plugin like EconomyGUI) then silently fails
     * to open because the client/server still think a window is active.
     * Explicitly removeWindow()-ing any tracked GUI before opening a new
     * one guarantees a clean slate every time.
     */
    private $activeGUI = [];

    /**
     * @var bool[] uuid => true while an old GUI's close (removeWindow +
     * its packets: ContainerClosePacket, original-block UpdateBlockPacket)
     * is still "in flight" client-side and a new GUI has not yet been
     * allowed to start opening for this player.
     *
     * FIXED (close/reopen race): closeActiveGui() used to call
     * removeWindow() and then, in the SAME server tick, construct+open a
     * brand new EnderBaseGUI at (very likely) the exact same fake-block
     * coordinates. On MCPE 0.14.3/0.15.x, sending a ContainerClosePacket
     * immediately followed by a fresh Air->Chest->NBT->ContainerOpenPacket
     * sequence for the same position, all within the same tick, is enough
     * to desync the legacy client's container state - the double chest in
     * particular (two block updates instead of one) reliably opened the
     * FIRST time (nothing to close first) but failed the SECOND time
     * (something to close immediately before reopening).
     *
     * Fix: closeActiveGui() now only sends the close; it sets this flag
     * and hands the actual "create + open the new GUI" work to
     * ReopenGuiTask, delayed by a tick. isPendingClose()/clearPendingClose()
     * let OpenContainerTask (see EnderBaseGUI::finishOpen) double-check
     * this flag before sending its own ContainerOpenPacket, in case two
     * opens got queued back-to-back.
     */
    private $pendingClose = [];

    public function onEnable(){
        @mkdir($this->getDataFolder());

        $this->manager = new EnderChestManager($this);
        $this->blockRegistry = new EnderChestBlockRegistry($this);

        $this->getServer()->getPluginManager()->registerEvents(new EnderChestListener($this), $this);

        EnderChestItemFactory::registerRecipe();

        // Particle task every 10 ticks (0.5s), offset rotates for a subtle spin effect
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new EnderChestParticleTask($this), 10);

        $this->getLogger()->info("EnderChest plugin enabled.");
    }

    public function getManager() : EnderChestManager{
        return $this->manager;
    }

    public function getBlockRegistry() : EnderChestBlockRegistry{
        return $this->blockRegistry;
    }

    public function getParticleAngleOffset() : float{
        $this->particleAngleOffset += 0.15;
        return $this->particleAngleOffset;
    }

    /**
     * Returns the EconomyAPI plugin instance if available, or null.
     * FIXED: EconomyAPI v2.0.9 has myMoney()/reduceMoney() directly on the
     * plugin instance. The old code called getProvider() which may not exist
     * on all EconomyAPI forks/versions. The listener now fetches the plugin
     * directly via PluginManager instead of going through this method.
     *
     * @deprecated Kept for backward compatibility only. New code should use
     *             Server::getPluginManager()->getPlugin("EconomyAPI") directly.
     */
    public function getEconomyProvider(){
        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if($economy === null){
            return null;
        }
        // EconomyAPI v2.0.9: myMoney/reduceMoney are on the plugin instance itself.
        // Return the plugin directly instead of calling getProvider().
        return $economy;
    }

    /**
     * Opens the player's personal ender inventory (shared across the world by UUID).
     * Size (27 or 54) is picked automatically based on their upgrade status.
     *
     * FIXED (close/reopen race): if a GUI is already open, the actual open
     * is deferred by a tick via ReopenGuiTask - see closeActiveGui() and
     * the $pendingClose doc comment above for why.
     */
    public function openPersonalEnderChest(Player $player){
        $uuid = $player->getUniqueId()->toString();

        if($this->closeActiveGui($player)){
            $this->getServer()->getScheduler()->scheduleDelayedTask(
                new ReopenGuiTask($this, $player, "single"),
                1
            );
            return;
        }

        $isDouble = $this->manager->isUpgraded($uuid);
        $gui = new PersonalEnderChestGUI($this, $player, $isDouble);
        $this->openGuiType[$uuid] = "single";
        $this->activeGUI[$uuid] = $gui;
        $player->addWindow($gui);
    }

    public function openUpgradeGui(Player $player){
        $uuid = $player->getUniqueId()->toString();

        if($this->closeActiveGui($player)){
            $this->getServer()->getScheduler()->scheduleDelayedTask(
                new ReopenGuiTask($this, $player, "upgrade"),
                1
            );
            return;
        }

        $gui = new UpgradeGUI($this, $player, false);
        $this->openGuiType[$uuid] = "upgrade";
        $this->activeGUI[$uuid] = $gui;
        $player->addWindow($gui);
    }

    /**
     * Called by ReopenGuiTask, a tick after closeActiveGui() flagged a
     * pending close, to actually build and open the requested GUI type.
     * Never called directly - go through openPersonalEnderChest()/
     * openUpgradeGui() instead, which handle the immediate (no prior GUI)
     * case themselves.
     */
    public function reopenGui(Player $player, $guiType){
        if(!$player->isOnline()){
            return;
        }

        $uuid = $player->getUniqueId()->toString();
        $this->clearPendingClose($player);

        if($guiType === "upgrade"){
            $gui = new UpgradeGUI($this, $player, false);
        }else{
            $isDouble = $this->manager->isUpgraded($uuid);
            $gui = new PersonalEnderChestGUI($this, $player, $isDouble);
        }

        $this->openGuiType[$uuid] = $guiType;
        $this->activeGUI[$uuid] = $gui;
        $player->addWindow($gui);
    }

    /**
     * Forcibly closes any EnderChest GUI still tracked as open for this
     * player. Same fix pattern as EconomyGUI's activeGUI check - see the
     * note on $activeGUI above.
     *
     * FIXED (close/reopen race): this now ONLY sends the close (removeWindow,
     * which triggers EnderBaseGUI::onClose() -> restore original block +
     * ContainerClosePacket) and marks pendingClose. It deliberately does
     * NOT construct or open a new GUI itself anymore - the caller is
     * responsible for deferring that by a tick when this returns true, so
     * the legacy client has a clean tick to process the close before any
     * new Air/Chest/NBT/Open packets arrive for the same coordinates.
     *
     * @return bool true if there WAS an active GUI (caller must defer the
     *              reopen), false if there was nothing to close (caller
     *              may open immediately).
     */
    private function closeActiveGui(Player $player){
        $uuid = $player->getUniqueId()->toString();
        if(isset($this->activeGUI[$uuid])){
            $oldGui = $this->activeGUI[$uuid];
            unset($this->activeGUI[$uuid]);
            $this->pendingClose[$uuid] = true;
            $player->removeWindow($oldGui);
            return true;
        }
        return false;
    }

    /**
     * Call this from onClose()/onInventoryClose() handling once a GUI is
     * confirmed closed, so activeGUI doesn't hold a stale reference.
     */
    public function clearActiveGui(Player $player){
        $uuid = $player->getUniqueId()->toString();
        unset($this->activeGUI[$uuid]);
    }

    /**
     * True while a previous GUI's close is still "in flight" for this
     * player (see $pendingClose doc comment above). EnderBaseGUI::finishOpen()
     * checks this before sending ContainerOpenPacket, as an extra guard on
     * top of ReopenGuiTask's own 1-tick delay, in case two opens somehow
     * got queued back-to-back for the same player.
     */
    public function isPendingClose(Player $player) : bool{
        $uuid = $player->getUniqueId()->toString();
        return isset($this->pendingClose[$uuid]);
    }

    public function clearPendingClose(Player $player){
        $uuid = $player->getUniqueId()->toString();
        unset($this->pendingClose[$uuid]);
    }

    public function getOpenGuiType(Player $player){
        $uuid = $player->getUniqueId()->toString();
        return $this->openGuiType[$uuid] ?? null;
    }

    public function clearOpenGuiType(Player $player){
        $uuid = $player->getUniqueId()->toString();
        unset($this->openGuiType[$uuid]);
    }

    // ============================================
    // ANTI-DUPE: MENU ITEM MARKER SWEEP
    // ============================================
    //
    // Every GUI tile/button in PersonalEnderChestGUI/UpgradeGUI is built
    // through EnderBaseGUI::setItemDisplay(), which tags the item with a
    // hidden NBT marker (EnderBaseGUI::MENU_ITEM_NBT_KEY). Anything
    // carrying that marker is GUI furniture, never a real player-owned
    // item, and gets stripped out the instant it's detected outside a GUI.
    // Same design as SmartSpawner's BaseGUI/isMenuItem()/
    // stripMenuItemsFromInventory() - mirrored here literally.
    //
    // WHY THIS EXISTS: relying only on cancelling the InventoryTransactionEvent
    // (EnderChestListener::onInventoryTransaction) is a single point of
    // defense. On legacy MCPE 0.14.3/0.15.10 clients, a press-and-hold/
    // hover on an inventory slot can trigger the client's own optimistic
    // client-side drag prediction, visually moving the GUI item into the
    // player's hotbar before the server's transaction cancel + resync ever
    // lands. This sweep is the safety net that catches and removes any
    // such item the moment it's noticed, on every event that could let a
    // player make use of it (transaction, inventory close, held-item
    // switch, drop, interact, quit).
    //
    // CAVEAT (same as SmartSpawner/EconomyGUI): this reduces the exploit
    // window to a single tick in the best case, it does not provide the
    // same hard guarantee as never letting a real/valuable item sit in a
    // GUI slot in the first place - some legacy clients don't fully trust/
    // apply a correction packet arriving fast after their own predicted
    // move.

    /**
     * @param Item|null $item
     * @return bool true if this item is a tagged GUI menu tile and should
     *              never be allowed to sit in a real player inventory.
     */
    public function isMenuItem($item) : bool{
        if($item === null || $item->getId() === Item::AIR){
            return false;
        }
        return EnderBaseGUI::isMenuItem($item);
    }

    /**
     * Scan a player's real inventory (not any GUI) for tagged menu items
     * and strip them out immediately.
     *
     * @param Player $player
     * @return int number of tainted stacks removed
     */
    public function stripMenuItemsFromInventory($player) : int{
        if($player === null || !($player instanceof Player) || !$player->isOnline()){
            return 0;
        }

        $inventory = $player->getInventory();
        $removed = 0;

        foreach($inventory->getContents() as $slot => $item){
            if($this->isMenuItem($item)){
                $inventory->clear($slot);
                $removed++;
            }
        }

        // Also check the currently held item explicitly - on some legacy
        // clients the "held" slot is reported separately from
        // getContents() during the exact tick a hotbar swap happens.
        $held = $inventory->getItemInHand();
        if($this->isMenuItem($held)){
            $inventory->setItemInHand(Item::get(Item::AIR));
            $removed++;
        }

        if($removed > 0){
            $inventory->sendContents($player);
        }

        return $removed;
    }
}