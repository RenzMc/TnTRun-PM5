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
        
        if (!$arenaFiles || !is_array($arenaFiles)) {
            $this->plugin->getLogger()->info("No arena files found to load.");
            return;
        }

        foreach ($arenaFiles as $arenaFile) {
            $arenaName = pathinfo($arenaFile, PATHINFO_FILENAME);
            
            try {
                $config = new Config($arenaFile, Config::YAML);
                
                // Additional safety check for corrupted config files
                if (!$config->exists("world")) {
                    $this->plugin->getLogger()->warning("Skipping corrupted arena config: " . $arenaName);
                    continue;
                }
                
                if ($this->validateArenaConfig($config)) {
                    // Pre-load the arena world to ensure it's available
                    $worldName = $config->get("world");
                    $worldManager = \pocketmine\Server::getInstance()->getWorldManager();
                    
                    if (!$worldManager->isWorldLoaded($worldName)) {
                        $this->plugin->getLogger()->debug("Pre-loading arena world '{$worldName}' for arena '{$arenaName}'");
                        if (!$worldManager->loadWorld($worldName)) {
                            $this->plugin->getLogger()->warning("Failed to pre-load world '{$worldName}' for arena '{$arenaName}'. Arena may not function correctly.");
                        } else {
                            $this->plugin->getLogger()->debug("Successfully pre-loaded world '{$worldName}' for arena '{$arenaName}'");
                        }
                    }
                    
                    // Pre-load the lobby world if it exists and is different from arena world
                    $lobbyWorld = $config->get("lobbyWorld", "");
                    if (!empty($lobbyWorld) && $lobbyWorld !== $worldName && !$worldManager->isWorldLoaded($lobbyWorld)) {
                        $this->plugin->getLogger()->debug("Pre-loading lobby world '{$lobbyWorld}' for arena '{$arenaName}'");
                        if (!$worldManager->loadWorld($lobbyWorld)) {
                            $this->plugin->getLogger()->warning("Failed to pre-load lobby world '{$lobbyWorld}' for arena '{$arenaName}'. Lobby teleportation may not work correctly.");
                        } else {
                            $this->plugin->getLogger()->debug("Successfully pre-loaded lobby world '{$lobbyWorld}' for arena '{$arenaName}'");
                        }
                    }
                    
                    $arena = new Arena(
                        $arenaName,
                        $worldName,
                        max(1, $config->get("minPlayers", 2)), // Ensure minimum 1 player
                        max(2, $config->get("maxPlayers", 10)), // Ensure minimum 2 max players
                        $lobbyWorld,
                        $config->get("lobbyPosition", []),
                        $this->validateSpawnPositions($config->get("spawnPositions", [])),
                        $config->get("blockType", "tnt")
                    );
                    
                    $this->arenas[$arenaName] = $arena;
                    $this->plugin->getLogger()->info("Loaded arena: " . $arenaName);
                } else {
                    $this->plugin->getLogger()->warning("Failed to load arena: " . $arenaName . " (invalid configuration)");
                }
            } catch (\Exception $e) {
                $this->plugin->getLogger()->error("Error loading arena config " . $arenaName . ": " . $e->getMessage());
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
        
        // Load default arena template with error handling
        $defaultTemplate = null;
        try {
            $defaultTemplate = new Config($this->plugin->getDataFolder() . "default_arena.yml", Config::YAML);
        } catch (\Exception $e) {
            $this->plugin->getLogger()->warning("Could not load default arena template: " . $e->getMessage());
        }
        
        // Create new arena config
        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $name . ".yml", Config::YAML);
        
        // Set basic properties
        $config->set("world", $world);
        $config->set("minPlayers", max(1, $minPlayers)); // Ensure minimum 1
        $config->set("maxPlayers", max(2, $maxPlayers)); // Ensure minimum 2
        
        // Copy default values for other properties
        $config->set("lobbyWorld", $defaultTemplate ? $defaultTemplate->get("lobbyWorld", "") : "");
        $config->set("lobbyPosition", $defaultTemplate ? $defaultTemplate->get("lobbyPosition", []) : []);
        $config->set("blockType", $defaultTemplate ? $defaultTemplate->get("blockType", "tnt") : "tnt");
        
        // Get safe Y coordinate for the world
        $safeY = $this->getSafeYForWorld($world);
        
        // Create default spawn positions based on maxPlayers
        $spawnPositions = [];
        for ($i = 0; $i < $maxPlayers; $i++) {
            if ($defaultTemplate && $defaultTemplate->getNested("spawnPositions.$i")) {
                $templateSpawn = $defaultTemplate->getNested("spawnPositions.$i");
                // Validate and use safe Y coordinate
                $spawnPositions[$i] = [
                    "x" => $templateSpawn["x"] ?? 0,
                    "y" => max(1, min(319, $templateSpawn["y"] ?? $safeY)),
                    "z" => $templateSpawn["z"] ?? 0
                ];
            } else {
                // Create a circular pattern for spawn positions if not enough in template
                $angle = (2 * M_PI * $i) / $maxPlayers;
                $radius = 5;
                $spawnPositions[$i] = [
                    "x" => round(sin($angle) * $radius),
                    "y" => $safeY,
                    "z" => round(cos($angle) * $radius)
                ];
            }
        }
        $config->set("spawnPositions", $spawnPositions);
        
        // Save the config with error handling
        try {
            $config->save();
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Failed to save arena config for " . $name . ": " . $e->getMessage());
            return false;
        }
        
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
        // Check required fields
        if (!$config->exists("world") || !$config->exists("minPlayers") || !$config->exists("maxPlayers")) {
            return false;
        }
        
        // Validate field types and values
        $minPlayers = $config->get("minPlayers");
        $maxPlayers = $config->get("maxPlayers");
        
        if (!is_int($minPlayers) || !is_int($maxPlayers) || $minPlayers < 1 || $maxPlayers < 2 || $minPlayers > $maxPlayers) {
            return false;
        }
        
        // Validate world name
        $world = $config->get("world");
        if (!is_string($world) || empty($world)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate spawn positions array and ensure safe coordinates
     */
    private function validateSpawnPositions(array $spawnPositions): array {
        $validatedSpawns = [];
        
        foreach ($spawnPositions as $key => $spawn) {
            if (!is_array($spawn)) {
                continue;
            }
            
            $validatedSpawns[$key] = [
                "x" => $spawn["x"] ?? 0,
                "y" => max(1, min(319, $spawn["y"] ?? 64)), // Ensure safe Y coordinate
                "z" => $spawn["z"] ?? 0
            ];
        }
        
        return $validatedSpawns;
    }
    
    /**
     * Get safe Y coordinate for a world
     */
    private function getSafeYForWorld(string $worldName): int {
        $worldManager = \pocketmine\Server::getInstance()->getWorldManager();
        
        // Try to get the world
        $world = $worldManager->getWorldByName($worldName);
        if ($world === null) {
            // Try to load the world
            if ($worldManager->loadWorld($worldName)) {
                $world = $worldManager->getWorldByName($worldName);
            }
        }
        
        if ($world !== null) {
            // Get world's spawn location Y coordinate as a safe baseline
            $spawnY = $world->getSpawnLocation()->getY();
            return max(1, min(319, (int)$spawnY));
        }
        
        // Fallback to a reasonable default
        return 64;
    }
}