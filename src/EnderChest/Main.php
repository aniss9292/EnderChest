<?php

namespace EnderChest;

use EnderChest\gui\PersonalEnderChestGUI;
use EnderChest\gui\UpgradeGUI;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\level\Position;

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
     */
    public function openPersonalEnderChest(Player $player){
        $uuid = $player->getUniqueId()->toString();
        $isDouble = $this->manager->isUpgraded($uuid);

        $this->closeActiveGui($player);

        $gui = new PersonalEnderChestGUI($this, $player, $isDouble);
        $this->openGuiType[$uuid] = "single";
        $this->activeGUI[$uuid] = $gui;
        $player->addWindow($gui);
    }

    public function openUpgradeGui(Player $player){
        $uuid = $player->getUniqueId()->toString();

        $this->closeActiveGui($player);

        $gui = new UpgradeGUI($this, $player, false);
        $this->openGuiType[$uuid] = "upgrade";
        $this->activeGUI[$uuid] = $gui;
        $player->addWindow($gui);
    }

    /**
     * Forcibly closes any EnderChest GUI still tracked as open for this
     * player, before a new one is opened. Same fix pattern as EconomyGUI's
     * activeGUI check - see the note on $activeGUI above.
     */
    private function closeActiveGui(Player $player){
        $uuid = $player->getUniqueId()->toString();
        if(isset($this->activeGUI[$uuid])){
            $oldGui = $this->activeGUI[$uuid];
            unset($this->activeGUI[$uuid]);
            $player->removeWindow($oldGui);
        }
    }

    /**
     * Call this from onClose()/onInventoryClose() handling once a GUI is
     * confirmed closed, so activeGUI doesn't hold a stale reference.
     */
    public function clearActiveGui(Player $player){
        $uuid = $player->getUniqueId()->toString();
        unset($this->activeGUI[$uuid]);
    }

    public function getOpenGuiType(Player $player){
        $uuid = $player->getUniqueId()->toString();
        return $this->openGuiType[$uuid] ?? null;
    }

    public function clearOpenGuiType(Player $player){
        $uuid = $player->getUniqueId()->toString();
        unset($this->openGuiType[$uuid]);
    }
}