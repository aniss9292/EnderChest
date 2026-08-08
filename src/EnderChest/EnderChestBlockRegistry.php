<?php

namespace EnderChest;

use pocketmine\utils\Config;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\Server;

class EnderChestBlockRegistry{

    /** @var Main */
    private $plugin;

    /** @var Config */
    private $config;

    /** @var array levelName => [ "x:y:z" => true ] */
    private $positions = [];

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        @mkdir($plugin->getDataFolder());
        $this->config = new Config($plugin->getDataFolder() . "enderchest_blocks.yml", Config::YAML, []);
        $this->positions = $this->config->getAll();
    }

    private function key(int $x, int $y, int $z) : string{
        return $x . ":" . $y . ":" . $z;
    }

    public function register(Position $pos){
        $levelName = $pos->getLevel()->getFolderName();
        if(!isset($this->positions[$levelName])){
            $this->positions[$levelName] = [];
        }
        $this->positions[$levelName][$this->key((int) $pos->x, (int) $pos->y, (int) $pos->z)] = true;
        $this->save();
    }

    public function unregister(Position $pos){
        $levelName = $pos->getLevel()->getFolderName();
        $k = $this->key((int) $pos->x, (int) $pos->y, (int) $pos->z);
        if(isset($this->positions[$levelName][$k])){
            unset($this->positions[$levelName][$k]);
            $this->save();
        }
    }

    public function isEnderChest(Position $pos) : bool{
        $levelName = $pos->getLevel()->getFolderName();
        $k = $this->key((int) $pos->x, (int) $pos->y, (int) $pos->z);
        return isset($this->positions[$levelName][$k]);
    }

    private function save(){
        $this->config->setAll($this->positions);
        $this->config->save();
    }

    /**
     * Returns all registered positions as Position objects for currently loaded levels only.
     * Used by the particle task so we don't try to touch unloaded levels.
     *
     * @return Position[]
     */
    public function getAllLoadedPositions() : array{
        $result = [];
        foreach($this->positions as $levelName => $coords){
            $level = Server::getInstance()->getLevelByName($levelName);
            if($level === null){
                continue;
            }
            foreach($coords as $key => $_){
                $parts = explode(":", $key);
                $x = $parts[0];
                $y = $parts[1];
                $z = $parts[2];
                $result[] = new Position((float) $x, (float) $y, (float) $z, $level);
            }
        }
        return $result;
    }
}
