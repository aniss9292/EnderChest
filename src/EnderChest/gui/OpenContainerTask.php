<?php

namespace EnderChest\gui;

use pocketmine\scheduler\Task;
use pocketmine\Player;

class OpenContainerTask extends Task{

    /** @var EnderBaseGUI */
    private $gui;

    /** @var Player */
    private $player;

    private $x;
    private $y;
    private $z;

    public function __construct(EnderBaseGUI $gui, Player $player, $x, $y, $z){
        $this->gui    = $gui;
        $this->player = $player;
        $this->x      = $x;
        $this->y      = $y;
        $this->z      = $z;
    }

    public function onRun($currentTick){
        if(!$this->player->isOnline()){
            return;
        }

        $this->gui->finishOpen($this->player, $this->x, $this->y, $this->z);
    }
}
