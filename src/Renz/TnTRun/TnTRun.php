<?php

declare(strict_types=1);

namespace Renz\TnTRun;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use Renz\TnTRun\commands\TnTRunCommand;
use Renz\TnTRun\arena\ArenaManager;
use Renz\TnTRun\listeners\EventListener;

class TnTRun extends PluginBase {
    use SingletonTrait;

    /** @var ArenaManager */
    private ArenaManager $arenaManager;

    /** @var Config */
    private Config $config;

    public function onLoad(): void {
        self::setInstance($this);
    }

    public function onEnable(): void {
        // Create directories
        @mkdir($this->getDataFolder());
        @mkdir($this->getDataFolder() . "arenas");

        // Save default resources
        $this->saveDefaultConfig();
        $this->saveResource("default_arena.yml", false);
        $this->config = $this->getConfig();

        // Initialize arena manager
        $this->arenaManager = new ArenaManager($this);

        // Register command
        $this->getServer()->getCommandMap()->register("tntrun", new TnTRunCommand($this));

        // Register event listeners
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

        $this->getLogger()->info("TnTRun plugin has been enabled!");
    }

    public function onDisable(): void {
        // Save all arena data
        if (isset($this->arenaManager)) {
            $this->arenaManager->saveAllArenas();
        }
        
        $this->getLogger()->info("TnTRun plugin has been disabled!");
    }

    /**
     * @return ArenaManager
     */
    public function getArenaManager(): ArenaManager {
        return $this->arenaManager;
    }
}