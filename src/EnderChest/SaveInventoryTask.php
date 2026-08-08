<?php

namespace EnderChest;

use pocketmine\scheduler\PluginTask;
use EnderChest\gui\PersonalEnderChestGUI;

/**
 * Deferred inventory save task.
 * Replaces ClosureTask (which doesn't exist on API 2.0.0 / Genisys).
 * Runs 1 tick after an inventory transaction so the slot mutation has
 * already been applied server-side before we snapshot the contents.
 */
class SaveInventoryTask extends PluginTask {

    /** @var EnderChestManager */
    private $manager;

    /** @var PersonalEnderChestGUI */
    private $inv;

    /** @var string */
    private $uuid;

    public function __construct(Main $plugin, EnderChestManager $manager, PersonalEnderChestGUI $inv, string $uuid) {
        parent::__construct($plugin);
        $this->manager = $manager;
        $this->inv = $inv;
        $this->uuid = $uuid;
    }

    public function onRun($currentTick) {
        $this->manager->saveItems($this->uuid, $this->inv->getContents());
    }
}