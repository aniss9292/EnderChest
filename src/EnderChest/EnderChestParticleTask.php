<?php

namespace EnderChest;

use pocketmine\scheduler\PluginTask;
use pocketmine\level\particle\PortalParticle;
use pocketmine\math\Vector3;

class EnderChestParticleTask extends PluginTask{

    /** @var Main */
    private $plugin;

    public function __construct(Main $plugin){
        parent::__construct($plugin);
        $this->plugin = $plugin;
    }

    public function onRun($currentTick){
        $registry = $this->plugin->getBlockRegistry();
        $positions = $registry->getAllLoadedPositions();

        foreach($positions as $pos){
            $level = $pos->getLevel();
            if($level === null){
                continue;
            }

            // Skip if no players are nearby (perf) - within 24 blocks radius
            $hasNearbyPlayer = false;
            foreach($level->getPlayers() as $player){
                if($player->distanceSquared($pos) <= (24 * 24)){
                    $hasNearbyPlayer = true;
                    break;
                }
            }
            if(!$hasNearbyPlayer){
                continue;
            }

            $this->spawnPortalRing($level, $pos);
        }
    }

    /**
     * FIXED: Portal particles now spawn from all 6 faces of the chest
     * block (top, bottom, north, south, east, west), each particle
     * appearing right at that face's surface and drifting outward —
     * matching vanilla Minecraft's real ender chest particle behavior,
     * instead of a single ring floating above the block.
     */
    private function spawnPortalRing($level, $pos){
        $bx = $pos->x; // block-space (integer) origin, NOT +0.5 centered
        $by = $pos->y;
        $bz = $pos->z;

        // A few particles per face per tick. Each particle spawns very
        // close to the face's surface (small jitter) so it visually
        // originates from inside the block and pokes out through that face.
        $perFace = 1;

        for($i = 0; $i < $perFace; $i++){
            // Random point on the block's XZ footprint / Y height, used to
            // jitter the particle's position along the face it emerges from.
            $jx = mt_rand(10, 90) / 100; // 0.1 - 0.9, avoid exact edges
            $jy = mt_rand(10, 90) / 100;
            $jz = mt_rand(10, 90) / 100;

            // Top face (y+1): particle sits just above the top surface
            $level->addParticle(new PortalParticle(new Vector3(
                $bx + $jx, $by + 1.0 + 0.05, $bz + $jz
            )));

            // Bottom face (y): particle sits just below the bottom surface
            $level->addParticle(new PortalParticle(new Vector3(
                $bx + $jx, $by - 0.05, $bz + $jz
            )));

            // North face (z, facing -Z)
            $level->addParticle(new PortalParticle(new Vector3(
                $bx + $jx, $by + $jy, $bz - 0.05
            )));

            // South face (z+1, facing +Z)
            $level->addParticle(new PortalParticle(new Vector3(
                $bx + $jx, $by + $jy, $bz + 1.0 + 0.05
            )));

            // West face (x, facing -X)
            $level->addParticle(new PortalParticle(new Vector3(
                $bx - 0.05, $by + $jy, $bz + $jz
            )));

            // East face (x+1, facing +X)
            $level->addParticle(new PortalParticle(new Vector3(
                $bx + 1.0 + 0.05, $by + $jy, $bz + $jz
            )));
        }
    }
}