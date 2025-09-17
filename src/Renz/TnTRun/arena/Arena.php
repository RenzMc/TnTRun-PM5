<?php

declare(strict_types=1);

namespace Renz\TnTRun\arena;

use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\sound\XpLevelUpSound;
use pocketmine\Server;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;
use Renz\TnTRun\TnTRun;

class Arena {
    // Arena status constants
    public const STATUS_WAITING = 0;
    public const STATUS_COUNTDOWN = 1;
    public const STATUS_PLAYING = 2;
    public const STATUS_ENDING = 3;
    public const STATUS_SETUP = 4;
    public const STATUS_RESETTING = 5;

    /** @var string */
    private string $name;
    
    /** @var string */
    private string $world;
    
    /** @var int */
    private int $minPlayers;
    
    /** @var int */
    private int $maxPlayers;
    
    /** @var string */
    private string $lobbyWorld;
    
    /** @var array */
    private array $lobbyPosition = [];
    
    /** @var array */
    private array $spawnPositions = [];
    
    /** @var string */
    private string $blockType;
    
    /** @var int */
    private int $status = self::STATUS_WAITING;
    
    /** @var array */
    private array $players = [];
    
    /** @var array */
    private array $spectators = [];
    
    /** @var array */
    private array $playerOriginalLocations = [];
    
    /** @var int */
    private int $countdownTime = 0;
    
    /** @var array */
    private array $votes = [];
    
    /** @var \pocketmine\scheduler\TaskHandler|null */
    private ?\pocketmine\scheduler\TaskHandler $gameMechanicsTask = null;
    
    /** @var \pocketmine\scheduler\TaskHandler|null */
    private ?\pocketmine\scheduler\TaskHandler $countdownTask = null;
    
    /** @var \pocketmine\scheduler\TaskHandler|null */
    private ?\pocketmine\scheduler\TaskHandler $preStartTask = null;
    
    /** @var int */
    private int $preStartTime = 0;

    /**
     * Arena constructor
     */
    public function __construct(
        string $name, 
        string $world, 
        int $minPlayers, 
        int $maxPlayers, 
        string $lobbyWorld = "", 
        array $lobbyPosition = [], 
        array $spawnPositions = [], 
        string $blockType = "tnt"
    ) {
        $this->name = $name;
        $this->world = $world;
        $this->minPlayers = $minPlayers;
        $this->maxPlayers = $maxPlayers;
        $this->lobbyWorld = $lobbyWorld;
        $this->lobbyPosition = $lobbyPosition;
        $this->spawnPositions = $spawnPositions;
        $this->blockType = $blockType;
    }

    /**
     * Join a player to the arena
     */
    public function joinPlayer(Player $player): bool {
        // Only allow joining during waiting state (not during reset or other states)
        if ($this->status !== self::STATUS_WAITING) {
            return false;
        }
        
        // Check if arena is full
        if (count($this->players) >= $this->maxPlayers) {
            return false;
        }
        
        // Check if player is already in an arena
        if (isset($this->players[$player->getName()])) {
            return false;
        }
        
        // Save player's original location
        $this->savePlayerLocation($player);
        
        // Add player to arena
        $this->players[$player->getName()] = $player;
        
        // Clear player inventory and set game mode
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setGamemode(\pocketmine\player\GameMode::ADVENTURE);
        $player->getXpManager()->setXpAndProgress(0, 0.0);
        
        // Teleport player to lobby or spawn
        if (!empty($this->lobbyWorld) && !empty($this->lobbyPosition)) {
            $this->teleportToLobby($player);
        } else {
            $this->teleportToArena($player);
        }
        
        // Give lobby items
        $this->giveLobbyItems($player);
        
        // Broadcast join message
        $this->broadcastMessage(TF::GREEN . $player->getName() . " joined the arena! (" . count($this->players) . "/" . $this->maxPlayers . ")");
        
        // Check if we should start countdown
        if (count($this->players) >= $this->minPlayers && $this->status === self::STATUS_WAITING) {
            $this->startCountdown();
        }
        
        return true;
    }

    /**
     * Remove a player from the arena
     */
    public function removePlayer(Player $player, bool $eliminated = false): void {
        $playerName = $player->getName();
        
        // Check if player is in the arena
        if (!isset($this->players[$playerName])) {
            return;
        }
        
        // Remove player from arena
        unset($this->players[$playerName]);
        
        // Clear player inventory
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        
        // Restore player's original location
        $this->restorePlayerLocation($player);
        
        // Broadcast leave message
        if (!$eliminated) {
            $this->broadcastMessage(TF::RED . $playerName . " left the arena! (" . count($this->players) . "/" . $this->maxPlayers . ")");
        }
        
        // Check if we need to end the game
        if ($this->status === self::STATUS_PLAYING && count($this->players) <= 1) {
            $this->endGame();
        }
        
        // Remove player's vote
        if (isset($this->votes[$playerName])) {
            unset($this->votes[$playerName]);
        }
    }

    /**
     * Start the countdown to begin the game
     */
    public function startCountdown(): void {
        if ($this->status !== self::STATUS_WAITING) {
            return;
        }
        
        // Cancel any existing countdown task
        $this->cancelCountdownTask();
        
        $this->status = self::STATUS_COUNTDOWN;
        $this->countdownTime = 10; // 10 seconds countdown
        
        // Play sound for countdown start
        foreach ($this->players as $player) {
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\AnvilUseSound());
        }
        
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name; // Avoid capturing $this
        
        $this->countdownTask = $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena === null) {
                    return;
                }
                
                $countdownTime = $arena->getCountdownTime();
                
                if ($countdownTime <= 0) {
                    $arena->startGame();
                    return;
                }
                
                // Check if we still have enough players
                if (count($arena->getPlayers()) < $arena->getMinPlayers()) {
                    $arena->cancelCountdown();
                    return;
                }
                
                if ($countdownTime <= 5 || $countdownTime % 5 === 0) {
                    $arena->broadcastTitle(TF::YELLOW . "Starting in " . TF::RED . $countdownTime . TF::YELLOW . " seconds!");
                    $arena->broadcastMessage(TF::YELLOW . "Game starting in " . TF::RED . $countdownTime . TF::YELLOW . " seconds!");
                    
                    // Play sound
                    foreach ($arena->getPlayers() as $player) {
                        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                    }
                }
                
                $arena->decrementCountdown();
            }
        ), 20); // Run every second
    }

    /**
     * Start the game
     */
    public function startGame(): void {
        if ($this->status !== self::STATUS_COUNTDOWN) {
            return;
        }
        
        // Cancel countdown task since game is starting
        $this->cancelCountdownTask();
        
        // Check if we have enough players (use minPlayers instead of hardcoded 2)
        if (count($this->players) < $this->minPlayers) {
            $this->broadcastMessage(TF::RED . "Not enough players to start the game! Need at least " . $this->minPlayers . " players.");
            $this->status = self::STATUS_WAITING;
            $this->countdownTime = 0;
            return;
        }
        
        // Teleport all players to arena and prepare them
        foreach ($this->players as $player) {
            $this->teleportToArena($player);
            
            // Clear inventory and give game items
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
        }
        
        // Start pre-start countdown (3-2-1-GO)
        $this->startPreStartCountdown();
    }

    /**
     * Start the game mechanics
     * Block removal is now handled in the PlayerMoveEvent
     */
    private function startGameMechanics(): void {
        // Cancel any existing game mechanics task
        $this->cancelGameMechanicsTask();
        
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name; // Avoid capturing $this in closure
        
        $this->gameMechanicsTask = $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena === null || $arena->getStatus() !== Arena::STATUS_PLAYING) {
                    return;
                }
                
                // Check for players who aren't moving but should still have blocks removed
                foreach ($arena->getPlayers() as $player) {
                    if (!$player->isOnline()) {
                        continue;
                    }
                    
                    $position = $player->getPosition();
                    $world = $position->getWorld();
                    
                    // Get block below player
                    $blockPos = $position->subtract(0, 1, 0)->floor();
                    $block = $world->getBlock($blockPos);
                    
                    // Don't remove air blocks
                    if (!$block->hasSameTypeId(\pocketmine\block\VanillaBlocks::AIR())) {
                        // Replace with air
                        $world->setBlock($blockPos, VanillaBlocks::AIR());
                        
                        // Play sound effect for block breaking
                        $world->addSound($blockPos, new \pocketmine\world\sound\BlockBreakSound($block));
                    }
                }
            }
        ), 20); // Run every second for non-moving players
    }

    /**
     * End the game
     */
    public function endGame(): void {
        if ($this->status !== self::STATUS_PLAYING) {
            return;
        }
        
        $this->status = self::STATUS_ENDING;
        
        // Cancel all active tasks immediately to prevent conflicts
        $this->cancelAllTasks();
        
        // Check for winner
        if (count($this->players) === 1) {
            $winner = reset($this->players);
            if ($winner instanceof Player && $winner->isOnline()) {
                $this->broadcastTitle(TF::GREEN . $winner->getName() . " wins!");
                $this->broadcastMessage(TF::GREEN . $winner->getName() . " has won the game!");
                
                // Play victory sound
                $winner->getWorld()->addSound($winner->getPosition(), new XpLevelUpSound(1));
            }
        } else {
            $this->broadcastTitle(TF::RED . "Game Over!");
            $this->broadcastMessage(TF::RED . "The game has ended with no winner!");
        }
        
        // Schedule reset task
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name; // Avoid capturing $this
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena !== null) {
                    $arena->resetArena();
                }
            }
        ), 100); // 5 seconds
    }

    /**
     * Reset the arena
     */
    public function resetArena(): void {
        $plugin = TnTRun::getInstance();
        $plugin->getLogger()->debug("Starting reset for arena {$this->name}");
        
        // Cancel ALL running tasks first (comprehensive safety)
        $this->cancelAllTasks();
        
        // Set to resetting status to prevent new joins
        $this->status = self::STATUS_RESETTING;
        
        // CRITICAL SAFETY: Ensure 0 players before world reset
        // Return all players to their original locations safely
        foreach ($this->players as $player) {
            if ($player instanceof Player && $player->isOnline()) {
                // Clear effects before restoring location
                $player->getEffects()->clear();
                $this->restorePlayerLocation($player);
                $plugin->getLogger()->debug("Restored player {$player->getName()} to original location during arena reset");
            }
        }
        
        // Clear all player data
        $this->players = [];
        $this->spectators = [];
        $this->votes = [];
        
        // Reset counters
        $this->countdownTime = 0;
        $this->preStartTime = 0;
        
        // Make sure the world is loaded before attempting to check if it's empty or restore it
        $worldManager = Server::getInstance()->getWorldManager();
        if (!$worldManager->isWorldLoaded($this->world)) {
            $plugin->getLogger()->debug("Loading arena world '{$this->world}' before reset");
            if (!$worldManager->loadWorld($this->world)) {
                $plugin->getLogger()->error("Failed to load arena world '{$this->world}' for reset - scheduling retry");
                $this->scheduleArenaResetRetry();
                return;
            }
        }
        
        // Attempt world restoration - only set to WAITING if successful
        if ($this->isArenaWorldEmpty()) {
            if ($this->restoreWorldFromBackup()) {
                // World restored successfully - arena is ready
                $this->status = self::STATUS_WAITING;
                $plugin->getLogger()->info("Arena {$this->name} reset completed successfully");
            } else {
                // World restoration failed - schedule retry
                $plugin->getLogger()->warning("Arena {$this->name} world restoration failed - scheduling retry");
                $this->scheduleArenaResetRetry();
            }
        } else {
            // World still has players - schedule retry
            $plugin->getLogger()->warning("Arena {$this->name} world reset skipped - players still in arena world - scheduling retry");
            $this->scheduleArenaResetRetry();
        }
    }

    /**
     * Schedule a retry for complete arena reset after delay
     */
    private function scheduleArenaResetRetry(): void {
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name;
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena !== null && $arena->getStatus() === Arena::STATUS_RESETTING) {
                    $arena->resetArena();
                }
            }
        ), 100); // 5 seconds delay with exponential backoff consideration
    }

    /**
     * Check if arena world is empty of all players and force-teleport any remaining
     */
    private function isArenaWorldEmpty(): bool {
        $world = Server::getInstance()->getWorldManager()->getWorldByName($this->world);
        if ($world === null) {
            return true; // World not loaded, consider it empty
        }
        
        // Check if any players are still in the arena world
        $playersInWorld = $world->getPlayers();
        
        // Force-teleport any remaining players (including untracked ones)
        foreach ($playersInWorld as $player) {
            if ($player instanceof Player && $player->isOnline()) {
                // Try to find their original location first
                if (isset($this->playerOriginalLocations[$player->getName()])) {
                    $this->restorePlayerLocation($player);
                } else {
                    // Fallback: teleport to server default world spawn
                    $defaultWorld = Server::getInstance()->getWorldManager()->getDefaultWorld();
                    if ($defaultWorld !== null) {
                        $player->teleport($defaultWorld->getSpawnLocation());
                        $player->sendMessage("You were teleported from arena world during reset.");
                    }
                }
            }
        }
        
        // Re-check if world is now empty
        $playersInWorld = $world->getPlayers();
        return empty($playersInWorld);
    }

    /**
     * Restore arena world from backup
     */
    private function restoreWorldFromBackup(): bool {
        $plugin = TnTRun::getInstance();
        $worldManager = Server::getInstance()->getWorldManager();
        
        // Check if backup exists (use world name for consistency)
        $backupDir = $plugin->getDataFolder() . "worlds" . DIRECTORY_SEPARATOR . $this->world;
        if (!is_dir($backupDir)) {
            $plugin->getLogger()->warning("No backup found for arena {$this->name} (world: {$this->world}) at {$backupDir}");
            return false;
        }
        
        $plugin->getLogger()->info("Restoring world {$this->world} for arena {$this->name} from backup");
        
        try {
            // Make sure the world is loaded before attempting to save it
            if (!$worldManager->isWorldLoaded($this->world)) {
                $plugin->getLogger()->debug("Loading world {$this->world} before saving and unloading for reset");
                if (!$worldManager->loadWorld($this->world)) {
                    $plugin->getLogger()->error("Failed to load world {$this->world} for arena {$this->name} before reset");
                    return false;
                }
            }
            
            // Save and unload the current world
            $world = $worldManager->getWorldByName($this->world);
            if ($world !== null) {
                $plugin->getLogger()->debug("Saving world {$this->world} before unloading for reset");
                $world->save(true);
                
                // Double check that no players are in the world
                if (count($world->getPlayers()) > 0) {
                    $plugin->getLogger()->warning("Players still in world {$this->world} during reset attempt - teleporting them out");
                    foreach ($world->getPlayers() as $player) {
                        // Teleport to server default world
                        $defaultWorld = $worldManager->getDefaultWorld();
                        if ($defaultWorld !== null) {
                            $player->teleport($defaultWorld->getSpawnLocation());
                            $player->sendMessage("You were teleported out of an arena world that is being reset.");
                        }
                    }
                }
                
                $plugin->getLogger()->debug("Unloading world {$this->world} for reset");
                if (!$worldManager->unloadWorld($world)) {
                    $plugin->getLogger()->error("Failed to unload world {$this->world} for arena {$this->name} - scheduling retry");
                    $this->scheduleWorldResetRetry();
                    return false;
                }
                $plugin->getLogger()->debug("Successfully unloaded world {$this->world}");
            }
            
            // Get world path
            $worldPath = Server::getInstance()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $this->world;
            
            // Remove current world data - CRITICAL: check for success
            if (is_dir($worldPath)) {
                $plugin->getLogger()->debug("Removing existing world data at {$worldPath}");
                if (!$this->removeDirectory($worldPath)) {
                    $plugin->getLogger()->error("Failed to remove existing world data at {$worldPath} for arena {$this->name}");
                    // Try to reload the world since we can't clean it
                    $worldManager->loadWorld($this->world);
                    return false;
                }
                $plugin->getLogger()->debug("Successfully removed existing world data");
            }
            
            // Copy backup to world location
            $plugin->getLogger()->debug("Copying backup from {$backupDir} to {$worldPath}");
            if (!$this->copyDirectory($backupDir, $worldPath)) {
                $plugin->getLogger()->error("Failed to restore world backup for arena {$this->name}");
                return false;
            }
            $plugin->getLogger()->debug("Successfully copied world backup");
            
            // Reload the world
            $plugin->getLogger()->debug("Reloading world {$this->world} after reset");
            if (!$worldManager->loadWorld($this->world)) {
                $plugin->getLogger()->error("Failed to reload world {$this->world} for arena {$this->name}");
                return false;
            }
            
            $plugin->getLogger()->info("Successfully restored world {$this->world} for arena {$this->name}");
            return true;
            
        } catch (\Throwable $e) {
            $plugin->getLogger()->error("Error restoring world for arena {$this->name}: " . $e->getMessage());
            // Try to reload the world as fallback
            try {
                $plugin->getLogger()->debug("Attempting to reload world after error");
                $worldManager->loadWorld($this->world);
            } catch (\Throwable $e2) {
                $plugin->getLogger()->error("Failed to reload world after error: " . $e2->getMessage());
            }
            return false;
        }
    }

    /**
     * Schedule a retry for world reset after delay
     */
    private function scheduleWorldResetRetry(): void {
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name;
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena !== null && $arena->isArenaWorldEmpty()) {
                    $arena->restoreWorldFromBackup();
                }
            }
        ), 200); // 10 seconds delay
    }

    /**
     * Recursively remove directory
     */
    private function removeDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return true;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                if (!rmdir($file->getPathname())) {
                    return false;
                }
            } else {
                if (!unlink($file->getPathname())) {
                    return false;
                }
            }
        }
        
        return rmdir($dir);
    }

    /**
     * Recursively copy directory
     */
    private function copyDirectory(string $source, string $destination): bool {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                return false;
            }
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $sourceBasePath = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $relativePath = substr($file->getPathname(), strlen($sourceBasePath));
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

            if ($file->isDir()) {
                if (!is_dir($destinationPath)) {
                    if (!mkdir($destinationPath, 0755, true)) {
                        return false;
                    }
                }
            } else {
                // Skip session lock files and other transient files
                if (str_ends_with($file->getFilename(), '.lock') || 
                    str_ends_with($file->getFilename(), '.tmp')) {
                    continue;
                }
                
                if (!copy($file->getPathname(), $destinationPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Save player's original location
     */
    private function savePlayerLocation(Player $player): void {
        $position = $player->getPosition();
        // Store player's original location and gamemode
        $gamemode = match ($player->getGamemode()) {
            \pocketmine\player\GameMode::SURVIVAL => 0,
            \pocketmine\player\GameMode::CREATIVE => 1,
            \pocketmine\player\GameMode::ADVENTURE => 2,
            \pocketmine\player\GameMode::SPECTATOR => 3,
            default => 0
        };
        
        $this->playerOriginalLocations[$player->getName()] = [
            "world" => $position->getWorld()->getFolderName(),
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ(),
            "yaw" => $player->getLocation()->getYaw(),
            "pitch" => $player->getLocation()->getPitch(),
            "gamemode" => $gamemode
        ];
    }

    /**
     * Restore player's original location
     */
    private function restorePlayerLocation(Player $player): void
{
    $playerName = $player->getName();

    if (!isset($this->playerOriginalLocations[$playerName])) {
        return;
    }

    $locationData = $this->playerOriginalLocations[$playerName];
    $world = Server::getInstance()->getWorldManager()->getWorldByName($locationData["world"]);

    if ($world !== null) {
        $location = \pocketmine\entity\Location::fromObject(
            new \pocketmine\math\Vector3(
                $locationData["x"],
                $locationData["y"],
                $locationData["z"]
            ),
            $world,
            $locationData["yaw"],
            $locationData["pitch"]
        );

        $player->teleport($location);

        // Restore player's original gamemode
        if (isset($locationData["gamemode"])) {
            $gamemode = match ($locationData["gamemode"]) {
                0 => \pocketmine\player\GameMode::SURVIVAL(),
                1 => \pocketmine\player\GameMode::CREATIVE(),
                2 => \pocketmine\player\GameMode::ADVENTURE(),
                3 => \pocketmine\player\GameMode::SPECTATOR(),
                default => \pocketmine\player\GameMode::SURVIVAL(), // fallback (optional)
            };

            $player->setGamemode($gamemode);
        }
    }

    unset($this->playerOriginalLocations[$playerName]);
}

    /**
     * Teleport player to lobby
     */
    private function teleportToLobby(Player $player): void {
        if (empty($this->lobbyWorld) || empty($this->lobbyPosition)) {
            // Fallback to arena teleport if lobby not configured
            $this->teleportToArena($player);
            return;
        }
        
        $worldManager = Server::getInstance()->getWorldManager();
        
        // Check if world is loaded, if not try to load it
        if (!$worldManager->isWorldLoaded($this->lobbyWorld)) {
            TnTRun::getInstance()->getLogger()->debug("Loading lobby world '{$this->lobbyWorld}' before teleporting player");
            if (!$worldManager->loadWorld($this->lobbyWorld)) {
                TnTRun::getInstance()->getLogger()->warning("Failed to load lobby world '{$this->lobbyWorld}', falling back to arena teleport");
                $this->teleportToArena($player);
                return;
            }
        }
        
        $world = $worldManager->getWorldByName($this->lobbyWorld);
        
        if ($world !== null && isset($this->lobbyPosition["x"], $this->lobbyPosition["y"], $this->lobbyPosition["z"])) {
            // Ensure Y coordinate is safe (not below 0 or above 320)
            $safeY = max(1, min(319, $this->lobbyPosition["y"]));
            
            $position = new Position(
                $this->lobbyPosition["x"],
                $safeY,
                $this->lobbyPosition["z"],
                $world
            );
            
            $player->teleport($position);
            TnTRun::getInstance()->getLogger()->debug("Teleported player {$player->getName()} to lobby in world '{$this->lobbyWorld}'");
        } else {
            // Ultimate fallback to arena teleport
            TnTRun::getInstance()->getLogger()->warning("Invalid lobby position or world, falling back to arena teleport");
            $this->teleportToArena($player);
        }
    }

    /**
     * Teleport player to arena
     */
    private function teleportToArena(Player $player): void {
        $worldManager = Server::getInstance()->getWorldManager();
        $plugin = TnTRun::getInstance();
        
        // Check if world is loaded, if not try to load it
        if (!$worldManager->isWorldLoaded($this->world)) {
            $plugin->getLogger()->debug("Loading arena world '{$this->world}' before teleporting player");
            if (!$worldManager->loadWorld($this->world)) {
                $plugin->getLogger()->error("Critical error: Arena world '{$this->world}' could not be loaded!");
                $player->sendMessage(TF::RED . "Error: Arena world could not be loaded!");
                return;
            }
        }
        
        $world = $worldManager->getWorldByName($this->world);
        
        if ($world === null) {
            $plugin->getLogger()->error("Critical error: Arena world '{$this->world}' is null after loading attempt!");
            $player->sendMessage(TF::RED . "Error: Arena world is not available!");
            return;
        }
        
        // Check if spawn positions are configured and not empty
        if (!empty($this->spawnPositions)) {
            $spawnIndex = array_rand($this->spawnPositions);
            $spawnPos = $this->spawnPositions[$spawnIndex];
            
            if (!empty($spawnPos) && isset($spawnPos["x"], $spawnPos["y"], $spawnPos["z"])) {
                // Ensure Y coordinate is safe (not below 0 or above 320)
                $safeY = max(1, min(319, $spawnPos["y"]));
                
                $position = new Position(
                    $spawnPos["x"],
                    $safeY,
                    $spawnPos["z"],
                    $world
                );
                
                $player->teleport($position);
                $plugin->getLogger()->debug("Teleported player {$player->getName()} to spawn position {$spawnIndex} in arena world '{$this->world}'");
                return;
            }
        }
        
        // Fallback to world's spawn point if no valid spawn positions
        $plugin->getLogger()->warning("No valid spawn positions found for arena '{$this->name}', using world spawn");
        $worldSpawn = $world->getSpawnLocation();
        // Ensure spawn Y is safe
        $safeSpawn = new Position(
            $worldSpawn->getX(),
            max(1, min(319, $worldSpawn->getY())),
            $worldSpawn->getZ(),
            $world
        );
        $player->teleport($safeSpawn);
        $plugin->getLogger()->debug("Teleported player {$player->getName()} to world spawn in arena world '{$this->world}'");
    }

    /**
     * Give lobby items to player
     */
    private function giveLobbyItems(Player $player): void {
        // Item to leave arena (slot 0)
        $leaveItem = \pocketmine\item\VanillaItems::REDSTONE_DUST();
        $leaveItem->setCustomName(TF::RED . "Leave Arena");
        $player->getInventory()->setItem(0, $leaveItem);
        
        // Item to vote for map (slot 4)
        $voteItem = \pocketmine\item\VanillaItems::COMPASS();
        $voteItem->setCustomName(TF::GREEN . "Vote for Map");
        $player->getInventory()->setItem(4, $voteItem);
    }

    /**
     * Broadcast a message to all players in the arena
     */
    public function broadcastMessage(string $message): void {
        foreach ($this->players as $player) {
            $player->sendMessage($message);
        }
        
        foreach ($this->spectators as $player) {
            $player->sendMessage($message);
        }
    }

    /**
     * Broadcast a title to all players in the arena
     */
    public function broadcastTitle(string $title, string $subtitle = ""): void {
        foreach ($this->players as $player) {
            $player->sendTitle($title, $subtitle);
        }
        
        foreach ($this->spectators as $player) {
            $player->sendTitle($title, $subtitle);
        }
    }

    /**
     * Handle player vote for map
     */
    public function addVote(Player $player, string $mapName): bool {
        if ($this->status !== self::STATUS_WAITING) {
            return false;
        }
        
        $this->votes[$player->getName()] = $mapName;
        return true;
    }

    /**
     * Get the most voted map
     */
    public function getMostVotedMap(): ?string {
        if (empty($this->votes)) {
            return null;
        }
        
        $voteCounts = array_count_values($this->votes);
        arsort($voteCounts);
        
        return key($voteCounts);
    }

    /**
     * Set arena in setup mode
     */
    public function setSetupMode(bool $setup): void {
        if ($setup) {
            $this->status = self::STATUS_SETUP;
        } else {
            $this->status = self::STATUS_WAITING;
        }
    }

    /**
     * Set spawn position
     */
    public function setSpawnPosition(int $index, Vector3 $position, World $world): void {
        $this->spawnPositions[$index] = [
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ()
        ];
        
        // Update world name if needed
        if ($this->world !== $world->getFolderName()) {
            $this->world = $world->getFolderName();
        }
    }

    /**
     * Set lobby position
     */
    public function setLobbyPosition(Vector3 $position, World $world): void {
        $this->lobbyWorld = $world->getFolderName();
        $this->lobbyPosition = [
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ()
        ];
    }

    /**
     * Set block type
     */
    public function setBlockType(string $blockType): void {
        // Validate block type (can be any valid block from VanillaBlocks)
        // Common types: tnt, sand, gravel, etc.
        $this->blockType = $blockType;
    }
    
     /**
     * Get the block instance based on the configured block type
     */
    public function getBlockInstance(): \pocketmine\block\Block {
        $method = strtoupper($this->blockType);
        $method = preg_replace('/[^A-Z0-9_]/', '_', $method); // sanitize

        // Cek method statis di VanillaBlocks
        if (method_exists(\pocketmine\block\VanillaBlocks::class, $method)) {
            try {
                return call_user_func([\pocketmine\block\VanillaBlocks::class, $method]);
            } catch (\Throwable $e) {
                return \pocketmine\block\VanillaBlocks::TNT();
            }
        }

        // fallback default ke TNT
        return \pocketmine\block\VanillaBlocks::TNT();
    }
    
    /**
     * Get arena name
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Get arena world
     */
    public function getWorld(): string {
        return $this->world;
    }

    /**
     * Get minimum players
     */
    public function getMinPlayers(): int {
        return $this->minPlayers;
    }

    /**
     * Get maximum players
     */
    public function getMaxPlayers(): int {
        return $this->maxPlayers;
    }

    /**
     * Get lobby world
     */
    public function getLobbyWorld(): string {
        return $this->lobbyWorld;
    }

    /**
     * Get lobby position
     */
    public function getLobbyPosition(): array {
        return $this->lobbyPosition;
    }

    /**
     * Get spawn positions
     */
    public function getSpawnPositions(): array {
        return $this->spawnPositions;
    }

    /**
     * Get block type
     */
    public function getBlockType(): string {
        return $this->blockType;
    }

    /**
     * Get arena status
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * Get players in arena
     * 
     * @return Player[]
     */
    public function getPlayers(): array {
        return $this->players;
    }
    
    /**
     * Cancel the game mechanics task if it's running
     */
    private function cancelGameMechanicsTask(): void {
        if ($this->gameMechanicsTask !== null && !$this->gameMechanicsTask->isCancelled()) {
            $this->gameMechanicsTask->cancel();
            $this->gameMechanicsTask = null;
        }
    }
    
    /**
     * Cancel the countdown task if it's running
     */
    private function cancelCountdownTask(): void {
        if ($this->countdownTask !== null && !$this->countdownTask->isCancelled()) {
            $this->countdownTask->cancel();
            $this->countdownTask = null;
        }
    }
    
    /**
     * Get current countdown time
     */
    public function getCountdownTime(): int {
        return $this->countdownTime;
    }
    
    /**
     * Decrement countdown time
     */
    public function decrementCountdown(): void {
        $this->countdownTime--;
    }
    
    /**
     * Cancel countdown and return to waiting state
     */
    public function cancelCountdown(): void {
        $this->cancelCountdownTask();
        $this->status = self::STATUS_WAITING;
        $this->countdownTime = 0;
        $this->broadcastMessage(TF::RED . "Countdown cancelled - not enough players!");
    }
    
    /**
     * Start the pre-start countdown (3-2-1-GO)
     */
    private function startPreStartCountdown(): void {
        // Cancel any existing pre-start task
        $this->cancelPreStartTask();
        
        $this->preStartTime = 4; // 4 seconds for 3-2-1-GO
        $this->broadcastTitle(TF::GREEN . "Get Ready!");
        $this->broadcastMessage(TF::GREEN . "The game is starting!");
        
        $plugin = TnTRun::getInstance();
        $arenaName = $this->name; // Avoid capturing $this
        
        $this->preStartTask = $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($plugin, $arenaName): void {
                $arena = $plugin->getArenaManager()->getArena($arenaName);
                if ($arena === null) {
                    return;
                }
                
                $preStartTime = $arena->getPreStartTime();
                
                // Check if arena state is still valid for pre-start
                if ($arena->getStatus() !== Arena::STATUS_COUNTDOWN || count($arena->getPlayers()) < $arena->getMinPlayers()) {
                    $arena->cancelPreStart();
                    return;
                }
                
                if ($preStartTime <= 0) {
                    // GO! - Start the actual game
                    $arena->finalizeGameStart();
                    return;
                }
                
                // Display countdown (3, 2, 1)
                if ($preStartTime <= 3) {
                    $arena->broadcastTitle(TF::YELLOW . (string)$preStartTime);
                    
                    // Play sound
                    foreach ($arena->getPlayers() as $player) {
                        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                    }
                }
                
                $arena->decrementPreStart();
            }
        ), 20); // Run every second
    }
    
    /**
     * Cancel the pre-start task if it's running
     */
    private function cancelPreStartTask(): void {
        if ($this->preStartTask !== null && !$this->preStartTask->isCancelled()) {
            $this->preStartTask->cancel();
            $this->preStartTask = null;
        }
    }
    
    /**
     * Cancel ALL tasks (comprehensive safety method)
     */
    public function cancelAllTasks(): void {
        $this->cancelCountdownTask();
        $this->cancelPreStartTask(); 
        $this->cancelGameMechanicsTask();
    }
    
    /**
     * Get current pre-start time
     */
    public function getPreStartTime(): int {
        return $this->preStartTime;
    }
    
    /**
     * Decrement pre-start time
     */
    public function decrementPreStart(): void {
        $this->preStartTime--;
    }
    
    /**
     * Cancel pre-start and reset arena
     */
    public function cancelPreStart(): void {
        $this->cancelPreStartTask();
        $this->resetArena();
    }
    
    /**
     * Finalize game start (called when pre-start countdown reaches 0)
     */
    public function finalizeGameStart(): void {
        // Cancel pre-start task
        $this->cancelPreStartTask();
        
        // Double-check we still have enough players
        if (count($this->players) < $this->minPlayers) {
            $this->broadcastMessage(TF::RED . "Not enough players to start the game!");
            $this->resetArena();
            return;
        }
        
        $this->broadcastTitle(TF::GREEN . "GO!");
        
        // Unfreeze players by removing slowness effect
        foreach ($this->players as $player) {
            $player->getEffects()->remove(\pocketmine\entity\effect\VanillaEffects::SLOWNESS());
        }
        
        // Play sound
        foreach ($this->players as $player) {
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ExplodeSound());
        }
        
        // Change status to playing
        $this->status = self::STATUS_PLAYING;
        
        // Start game mechanics
        $this->startGameMechanics();
    }
}