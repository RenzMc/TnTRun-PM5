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
        // Check PHP version compatibility
        if (PHP_VERSION_ID < 80200) {
            $this->getLogger()->error("TnTRun requires PHP 8.2 or higher. Current version: " . PHP_VERSION);
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        // Create directories with proper error handling
        $dataFolder = $this->getDataFolder();
        if (!is_dir($dataFolder)) {
            if (!mkdir($dataFolder, 0755, true)) {
                $this->getLogger()->error("Failed to create data folder: " . $dataFolder);
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
        }
        
        $arenasFolder = $dataFolder . "arenas";
        if (!is_dir($arenasFolder)) {
            if (!mkdir($arenasFolder, 0755, true)) {
                $this->getLogger()->error("Failed to create arenas folder: " . $arenasFolder);
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
        }

        // Save default resources with error handling
        try {
            $this->saveDefaultConfig();
            $this->saveResource("default_arena.yml", false);
            $this->config = $this->getConfig();
        } catch (\Throwable $e) {
            $this->getLogger()->error("Failed to save default resources: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Initialize arena manager
        try {
            $this->arenaManager = new ArenaManager($this);
        } catch (\Throwable $e) {
            $this->getLogger()->error("Failed to initialize ArenaManager: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Register command
        $this->getServer()->getCommandMap()->register("tntrun", new TnTRunCommand($this));

        // Register event listeners
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

        $this->getLogger()->info("TnTRun v1.0.1 has been enabled! Compatible with PocketMine-MP 5.32.0");
    }

    public function onDisable(): void {
        // Defensive cleanup: reset all arenas to cancel tasks and restore players
        if (isset($this->arenaManager) && $this->arenaManager !== null) {
            try {
                // First cancel all tasks to prevent any new operations
                foreach ($this->arenaManager->getArenas() as $arena) {
                    $arena->cancelAllTasks();
                }
                
                // Then safely reset all arenas to restore players
                foreach ($this->arenaManager->getArenas() as $arena) {
                    try {
                        $arena->resetArena();
                    } catch (\Throwable $e) {
                        $this->getLogger()->error("Error resetting arena {$arena->getName()}: " . $e->getMessage());
                    }
                }
                
                // Save all arena data
                try {
                    $this->arenaManager->saveAllArenas();
                    $this->getLogger()->info("All arena data saved successfully.");
                } catch (\Throwable $e) {
                    $this->getLogger()->error("Error saving arena data: " . $e->getMessage());
                }
            } catch (\Throwable $e) {
                $this->getLogger()->error("Error during plugin disable cleanup: " . $e->getMessage());
            }
        }
        
        $this->getLogger()->info("TnTRun plugin has been disabled!");
    }

    /**
     * @return ArenaManager
     */
    public function getArenaManager(): ArenaManager {
        if (!isset($this->arenaManager)) {
            throw new \RuntimeException("ArenaManager is not initialized");
        }
        return $this->arenaManager;
    }
}