<?php

namespace EnderChest;

use pocketmine\scheduler\PluginTask;
use pocketmine\level\Position;

/**
 * Deferred block registration task.
 * Replaces ClosureTask (which doesn't exist on API 2.0.0 / Genisys).
 * Runs 2 ticks after BlockPlaceEvent so the chest tile entity exists.
 */
class BlockRegisterTask extends PluginTask {

    /** @var EnderChestBlockRegistry */
    private $registry;

    /** @var Position */
    private $pos;

    public function __construct(Main $plugin, EnderChestBlockRegistry $registry, Position $pos) {
        parent::__construct($plugin);
        $this->registry = $registry;
        $this->pos = $pos;
    }

    public function onRun($currentTick) {
        $this->registry->register($this->pos);
    }
}