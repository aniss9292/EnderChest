<?php

/*
 * FIXED (close/reopen race, double-chest EnderChest GUI):
 *
 * Main::closeActiveGui() now only sends the close (removeWindow(), which
 * triggers EnderBaseGUI::onClose() -> restore original block(s) +
 * ContainerClosePacket) and flags pendingClose. It deliberately does NOT
 * build/open the new GUI in the same tick anymore.
 *
 * Building and opening the new GUI in the SAME tick as the close used to
 * mean: ContainerClosePacket + original-block UpdateBlockPacket(s) sent,
 * immediately followed (same tick) by a fresh Air->Chest->NBT sequence
 * for the SAME coordinates (very likely, since both GUIs use the fake
 * block position derived from the player's current standing spot). On
 * MCPE 0.14.3/0.15.x this reliably worked the FIRST time you opened
 * (nothing to close first) but broke the SECOND time (close+reopen
 * collision) - worse for the double chest since it's two block updates
 * instead of one for the client to process.
 *
 * This task is the deferred half: scheduled 1 tick after closeActiveGui()
 * flags pendingClose, giving the legacy client a clean tick to fully
 * process the close before any new open packets for the same position
 * arrive.
 */

namespace EnderChest\gui;

use pocketmine\scheduler\Task;
use pocketmine\Player;
use EnderChest\Main;

class ReopenGuiTask extends Task{

    /** @var Main */
    private $plugin;

    /** @var Player */
    private $player;

    /** @var string "single" or "upgrade" */
    private $guiType;

    public function __construct(Main $plugin, Player $player, $guiType){
        $this->plugin  = $plugin;
        $this->player  = $player;
        $this->guiType = $guiType;
    }

    public function onRun($currentTick){
        if(!$this->player->isOnline()){
            return;
        }

        $this->plugin->reopenGui($this->player, $this->guiType);
    }
}
