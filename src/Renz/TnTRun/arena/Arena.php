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
        // Check if arena is full
        if (count($this->players) >= $this->maxPlayers && $this->status === self::STATUS_WAITING) {
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
        $player->setGamemode(\pocketmine\player\GameMode::ADVENTURE());
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
        
        $this->status = self::STATUS_COUNTDOWN;
        $this->countdownTime = 10; // 10 seconds countdown
        
        // Play sound for countdown start
        foreach ($this->players as $player) {
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\AnvilUseSound());
        }
        
        $plugin = TnTRun::getInstance();
        $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($plugin): void {
                if ($this->countdownTime <= 0) {
                    $this->startGame();
                    return;
                }
                
                if ($this->countdownTime <= 5 || $this->countdownTime % 5 === 0) {
                    $this->broadcastTitle(TF::YELLOW . "Starting in " . TF::RED . $this->countdownTime . TF::YELLOW . " seconds!");
                    $this->broadcastMessage(TF::YELLOW . "Game starting in " . TF::RED . $this->countdownTime . TF::YELLOW . " seconds!");
                    
                    // Play sound
                    foreach ($this->players as $player) {
                        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                    }
                }
                
                $this->countdownTime--;
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
        
        // Check if we have enough players
        if (count($this->players) < 2) {
            $this->broadcastMessage(TF::RED . "Not enough players to start the game!");
            $this->status = self::STATUS_WAITING;
            return;
        }
        
        $this->status = self::STATUS_PLAYING;
        
        // Teleport all players to arena
        foreach ($this->players as $player) {
            $this->teleportToArena($player);
            
            // Clear inventory and give game items
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            
            // Freeze players
            $player->setImmobile(true);
        }
        
        // Start game countdown
        $this->broadcastTitle(TF::GREEN . "Get Ready!");
        $this->broadcastMessage(TF::GREEN . "The game is starting!");
        
        $plugin = TnTRun::getInstance();
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin): void {
                $this->broadcastTitle(TF::YELLOW . "3");
                
                // Play sound
                foreach ($this->players as $player) {
                    $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                }
            }
        ), 20); // 1 second
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin): void {
                $this->broadcastTitle(TF::YELLOW . "2");
                
                // Play sound
                foreach ($this->players as $player) {
                    $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                }
            }
        ), 40); // 2 seconds
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin): void {
                $this->broadcastTitle(TF::YELLOW . "1");
                
                // Play sound
                foreach ($this->players as $player) {
                    $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
                }
            }
        ), 60); // 3 seconds
        
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin): void {
                $this->broadcastTitle(TF::GREEN . "GO!");
                
                // Unfreeze players
                foreach ($this->players as $player) {
                    $player->setImmobile(false);
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
        ), 80); // 4 seconds
    }

    /**
     * Start the game mechanics
     * Block removal is now handled in the PlayerMoveEvent
     */
    private function startGameMechanics(): void {
        $plugin = TnTRun::getInstance();
        $plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function() use ($plugin): void {
                if ($this->status !== self::STATUS_PLAYING) {
                    return;
                }
                
                // Check for players who aren't moving but should still have blocks removed
                foreach ($this->players as $player) {
                    $position = $player->getPosition();
                    $world = $position->getWorld();
                    
                    // Get block below player
                    $blockPos = $position->subtract(0, 1, 0)->floor();
                    $block = $world->getBlock($blockPos);
                    
                    // Don't remove air blocks
                    if ($block->getId() !== 0) {
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
        
        // Check for winner
        if (count($this->players) === 1) {
            $winner = reset($this->players);
            $this->broadcastTitle(TF::GREEN . $winner->getName() . " wins!");
            $this->broadcastMessage(TF::GREEN . $winner->getName() . " has won the game!");
            
            // Play victory sound
            $winner->getWorld()->addSound($winner->getPosition(), new \pocketmine\world\sound\LevelUpSound());
        } else {
            $this->broadcastTitle(TF::RED . "Game Over!");
            $this->broadcastMessage(TF::RED . "The game has ended with no winner!");
        }
        
        // Schedule reset task
        $plugin = TnTRun::getInstance();
        $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($plugin): void {
                $this->resetArena();
            }
        ), 100); // 5 seconds
    }

    /**
     * Reset the arena
     */
    public function resetArena(): void {
        // Return all players to their original locations
        foreach ($this->players as $player) {
            $this->restorePlayerLocation($player);
        }
        
        // Clear players
        $this->players = [];
        $this->spectators = [];
        $this->votes = [];
        
        // Reset status
        $this->status = self::STATUS_WAITING;
    }

    /**
     * Save player's original location
     */
    private function savePlayerLocation(Player $player): void {
        $position = $player->getPosition();
        $this->playerOriginalLocations[$player->getName()] = [
            "world" => $position->getWorld()->getFolderName(),
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ(),
            "yaw" => $player->getLocation()->getYaw(),
            "pitch" => $player->getLocation()->getPitch(),
            "gamemode" => $player->getGamemode()->getId()
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
            $position = new Position(
                $locationData["x"],
                $locationData["y"],
                $locationData["z"],
                $world
            );
            
            $player->teleport($position, $locationData["yaw"], $locationData["pitch"]);
            
            // Restore player's original gamemode
            if (isset($locationData["gamemode"])) {
                $player->setGamemode(\pocketmine\player\GameMode::fromInt($locationData["gamemode"]));
            }
        }
        
        unset($this->playerOriginalLocations[$playerName]);
    }

    /**
     * Teleport player to lobby
     */
    private function teleportToLobby(Player $player): void {
        $world = Server::getInstance()->getWorldManager()->getWorldByName($this->lobbyWorld);
        
        if ($world !== null && !empty($this->lobbyPosition)) {
            $position = new Position(
                $this->lobbyPosition["x"],
                $this->lobbyPosition["y"],
                $this->lobbyPosition["z"],
                $world
            );
            
            $player->teleport($position);
        }
    }

    /**
     * Teleport player to arena
     */
    private function teleportToArena(Player $player): void {
        $world = Server::getInstance()->getWorldManager()->getWorldByName($this->world);
        
        if ($world === null) {
            return;
        }
        
        // Get spawn position
        $spawnIndex = array_rand($this->spawnPositions);
        $spawnPos = $this->spawnPositions[$spawnIndex];
        
        if (!empty($spawnPos)) {
            $position = new Position(
                $spawnPos["x"],
                $spawnPos["y"],
                $spawnPos["z"],
                $world
            );
            
            $player->teleport($position);
        } else {
            // Use world's spawn point if no specific spawn is set
            $player->teleport($world->getSpawnLocation());
        }
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
}