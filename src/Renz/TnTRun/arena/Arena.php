<?php

declare(strict_types=1);

namespace Renz\TnTRun\arena;

use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;
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
        // Only allow joining during waiting state
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
            
            // Freeze players by setting effect (compatible alternative)
            $player->getEffects()->add(new \pocketmine\entity\effect\EffectInstance(
                \pocketmine\entity\effect\VanillaEffects::SLOWNESS(),
                99999, // Duration in ticks (very long)
                255,   // Amplifier (maximum slowness)
                false  // Not visible
            ));
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
                    if (!$block->isSameType(\pocketmine\block\VanillaBlocks::AIR())) {
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
                $winner->getWorld()->addSound($winner->getPosition(), new \pocketmine\world\sound\LevelUpSound());
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
        // Cancel ALL running tasks first (comprehensive safety)
        $this->cancelAllTasks();
        
        // Return all players to their original locations safely
        foreach ($this->players as $player) {
            if ($player instanceof Player && $player->isOnline()) {
                // Clear effects before restoring location
                $player->getEffects()->clear();
                $this->restorePlayerLocation($player);
            }
        }
        
        // Clear all player data
        $this->players = [];
        $this->spectators = [];
        $this->votes = [];
        
        // Reset status and all counters
        $this->status = self::STATUS_WAITING;
        $this->countdownTime = 0;
        $this->preStartTime = 0;
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
    private function restorePlayerLocation(Player $player): void {
        $playerName = $player->getName();
        
        if (!isset($this->playerOriginalLocations[$playerName])) {
            return;
        }
        
        $locationData = $this->playerOriginalLocations[$playerName];
        $world = Server::getInstance()->getWorldManager()->getWorldByName($locationData["world"]);
        
        if ($world !== null) {
            $location = new \pocketmine\world\Location(
                $locationData["x"],
                $locationData["y"],
                $locationData["z"],
                $world,
                $locationData["yaw"],
                $locationData["pitch"]
            );
            
            $player->teleport($location);
            
            // Restore player's original gamemode
            if (isset($locationData["gamemode"])) {
                // Convert integer gamemode to enum case
                switch ($locationData["gamemode"]) {
                    case 0:
                        $player->setGamemode(\pocketmine\player\GameMode::SURVIVAL);
                        break;
                    case 1:
                        $player->setGamemode(\pocketmine\player\GameMode::CREATIVE);
                        break;
                    case 2:
                        $player->setGamemode(\pocketmine\player\GameMode::ADVENTURE);
                        break;
                    case 3:
                        $player->setGamemode(\pocketmine\player\GameMode::SPECTATOR);
                        break;
                }
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
        
        $world = Server::getInstance()->getWorldManager()->getWorldByName($this->lobbyWorld);
        
        if ($world === null) {
            // Try to load the world first
            if (!Server::getInstance()->getWorldManager()->loadWorld($this->lobbyWorld)) {
                // Fallback to arena teleport if lobby world fails to load
                $this->teleportToArena($player);
                return;
            }
            $world = Server::getInstance()->getWorldManager()->getWorldByName($this->lobbyWorld);
        }
        
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
        } else {
            // Ultimate fallback to arena teleport
            $this->teleportToArena($player);
        }
    }

    /**
     * Teleport player to arena
     */
    private function teleportToArena(Player $player): void {
        $world = Server::getInstance()->getWorldManager()->getWorldByName($this->world);
        
        if ($world === null) {
            // Try to load the world first
            if (!Server::getInstance()->getWorldManager()->loadWorld($this->world)) {
                // Critical error - cannot load arena world
                $player->sendMessage(TF::RED . "Error: Arena world could not be loaded!");
                return;
            }
            $world = Server::getInstance()->getWorldManager()->getWorldByName($this->world);
            
            if ($world === null) {
                $player->sendMessage(TF::RED . "Error: Arena world is not available!");
                return;
            }
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
                return;
            }
        }
        
        // Fallback to world's spawn point if no valid spawn positions
        $worldSpawn = $world->getSpawnLocation();
        // Ensure spawn Y is safe
        $safeSpawn = new Position(
            $worldSpawn->getX(),
            max(1, min(319, $worldSpawn->getY())),
            $worldSpawn->getZ(),
            $world
        );
        $player->teleport($safeSpawn);
    }

    /**
     * Give lobby items to player
     */
    private function giveLobbyItems(Player $player): void {
        // Item to leave arena (slot 0)
        $leaveItem = \pocketmine\item\VanillaItems::REDSTONE();
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
        // Convert string block type to actual block instance
        $blockType = strtolower($this->blockType);
        
        switch ($blockType) {
            case "tnt":
                return VanillaBlocks::TNT();
            case "sand":
                return VanillaBlocks::SAND();
            case "gravel":
                return VanillaBlocks::GRAVEL();
            case "dirt":
                return VanillaBlocks::DIRT();
            case "stone":
                return VanillaBlocks::STONE();
            case "grass":
                return VanillaBlocks::GRASS();
            case "planks":
            case "wood":
                return VanillaBlocks::OAK_PLANKS();
            default:
                // Default to TNT if block type is not recognized
                return VanillaBlocks::TNT();
        }
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