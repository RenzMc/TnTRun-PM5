<?php

declare(strict_types=1);

namespace Renz\TnTRun\arena;

use pocketmine\utils\Config;
use Renz\TnTRun\TnTRun;

class ArenaManager {
    /** @var TnTRun */
    private TnTRun $plugin;

    /** @var Arena[] */
    private array $arenas = [];

    public function __construct(TnTRun $plugin) {
        $this->plugin = $plugin;
        $this->loadArenas();
    }

    /**
     * Load all arenas from config files
     */
    public function loadArenas(): void {
        $arenaFiles = glob($this->plugin->getDataFolder() . "arenas/*.yml");
        
        if (!$arenaFiles) {
            return;
        }

        foreach ($arenaFiles as $arenaFile) {
            $config = new Config($arenaFile, Config::YAML);
            $arenaName = pathinfo($arenaFile, PATHINFO_FILENAME);
            
            if ($this->validateArenaConfig($config)) {
                $arena = new Arena(
                    $arenaName,
                    $config->get("world"),
                    $config->get("minPlayers"),
                    $config->get("maxPlayers"),
                    $config->get("lobbyWorld", ""),
                    $config->get("lobbyPosition", []),
                    $config->get("spawnPositions", []),
                    $config->get("blockType", "tnt")
                );
                
                $this->arenas[$arenaName] = $arena;
                $this->plugin->getLogger()->info("Loaded arena: " . $arenaName);
            } else {
                $this->plugin->getLogger()->warning("Failed to load arena: " . $arenaName . " (invalid configuration)");
            }
        }
    }

    /**
     * Save all arenas to config files
     */
    public function saveAllArenas(): void {
        foreach ($this->arenas as $arena) {
            $this->saveArena($arena);
        }
    }

    /**
     * Save a specific arena to its config file
     */
    public function saveArena(Arena $arena): void {
        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $arena->getName() . ".yml", Config::YAML);
        
        $config->set("world", $arena->getWorld());
        $config->set("minPlayers", $arena->getMinPlayers());
        $config->set("maxPlayers", $arena->getMaxPlayers());
        $config->set("lobbyWorld", $arena->getLobbyWorld());
        $config->set("lobbyPosition", $arena->getLobbyPosition());
        $config->set("spawnPositions", $arena->getSpawnPositions());
        $config->set("blockType", $arena->getBlockType());
        
        $config->save();
    }

    /**
     * Create a new arena
     */
    public function createArena(string $name, string $world, int $minPlayers, int $maxPlayers): bool {
        // Validate arena name (only alphanumeric characters allowed)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            return false;
        }
        
        // Check if arena already exists
        if (isset($this->arenas[$name])) {
            return false;
        }
        
        // Create arena object
        $arena = new Arena($name, $world, $minPlayers, $maxPlayers);
        $this->arenas[$name] = $arena;
        
        // Load default arena template
        $defaultTemplate = new Config($this->plugin->getDataFolder() . "default_arena.yml", Config::YAML);
        
        // Create new arena config
        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $name . ".yml", Config::YAML);
        
        // Set basic properties
        $config->set("world", $world);
        $config->set("minPlayers", $minPlayers);
        $config->set("maxPlayers", $maxPlayers);
        
        // Copy default values for other properties
        $config->set("lobbyWorld", $defaultTemplate->get("lobbyWorld", ""));
        $config->set("lobbyPosition", $defaultTemplate->get("lobbyPosition", []));
        $config->set("blockType", $defaultTemplate->get("blockType", "tnt"));
        
        // Create default spawn positions based on maxPlayers
        $spawnPositions = [];
        for ($i = 0; $i < $maxPlayers; $i++) {
            if ($defaultTemplate->getNested("spawnPositions.$i")) {
                $spawnPositions[$i] = $defaultTemplate->getNested("spawnPositions.$i");
            } else {
                // Create a circular pattern for spawn positions if not enough in template
                $angle = (2 * M_PI * $i) / $maxPlayers;
                $radius = 5;
                $spawnPositions[$i] = [
                    "x" => round(sin($angle) * $radius),
                    "y" => 64,
                    "z" => round(cos($angle) * $radius)
                ];
            }
        }
        $config->set("spawnPositions", $spawnPositions);
        
        // Save the config
        $config->save();
        
        return true;
    }

    /**
     * Get an arena by name
     */
    public function getArena(string $name): ?Arena {
        return $this->arenas[$name] ?? null;
    }

    /**
     * Get all arenas
     * 
     * @return Arena[]
     */
    public function getArenas(): array {
        return $this->arenas;
    }

    /**
     * Get a random arena with available slots
     */
    public function getRandomArena(): ?Arena {
        $availableArenas = [];
        
        foreach ($this->arenas as $arena) {
            if ($arena->getStatus() === Arena::STATUS_WAITING && count($arena->getPlayers()) < $arena->getMaxPlayers()) {
                $availableArenas[] = $arena;
            }
        }
        
        if (count($availableArenas) === 0) {
            return null;
        }
        
        return $availableArenas[array_rand($availableArenas)];
    }

    /**
     * Validate arena configuration
     */
    private function validateArenaConfig(Config $config): bool {
        return $config->exists("world") && 
               $config->exists("minPlayers") && 
               $config->exists("maxPlayers");
    }
}