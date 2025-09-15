<?php

declare(strict_types=1);

namespace Renz\TnTRun\listeners;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use Renz\TnTRun\TnTRun;
use Renz\TnTRun\arena\Arena;
use Renz\TnTRun\forms\TnTRunForm;

class EventListener implements Listener {
    /** @var TnTRun */
    private TnTRun $plugin;

    public function __construct(TnTRun $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Handle player interaction events
     */
    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena === null) {
            return;
        }
        
        // Handle lobby items
        if ($arena->getStatus() === Arena::STATUS_WAITING || $arena->getStatus() === Arena::STATUS_COUNTDOWN) {
            $itemName = $item->getCustomName();
            
            if ($itemName === TF::RED . "Leave Arena") {
                $event->cancel();
                $arena->removePlayer($player);
                $player->sendMessage(TF::GREEN . "You left the arena.");
            } elseif ($itemName === TF::GREEN . "Vote for Map") {
                $event->cancel();
                
                // Check if FormAPI is available before opening forms
                if (!class_exists(\jojoe77777\FormAPI\SimpleForm::class)) {
                    $player->sendMessage(TF::RED . "Forms are not available. FormAPI plugin is required for voting.");
                    return;
                }
                
                TnTRunForm::sendVoteForm($player);
            }
        }
    }

    /**
     * Handle player quit events
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena !== null) {
            // Remove player from arena and clean up all related data
            $arena->removePlayer($player);
            
            // Check if there's only one player left (winner) - safe handling
            if ($arena->getStatus() === Arena::STATUS_PLAYING && count($arena->getPlayers()) === 1) {
                $players = $arena->getPlayers();
                $winner = reset($players); // More reliable than array_values
                
                if ($winner instanceof Player && $winner->isOnline()) {
                    $arena->broadcastTitle(TF::GOLD . $winner->getName() . TF::GREEN . " wins!");
                    $arena->broadcastMessage(TF::GOLD . $winner->getName() . TF::GREEN . " has won the game!");
                    
                    // Play victory sound
                    $winner->getWorld()->addSound($winner->getPosition(), new \pocketmine\world\sound\LevelUpSound());
                }
                
                // End the game regardless of winner state
                $arena->endGame();
            }
        }
        
        // Check if player is in setup mode
        $setupData = $this->plugin->getConfig()->get("setup-locations." . $player->getName());
        if ($setupData !== null) {
            // Clear setup mode for this player
            $this->plugin->getConfig()->remove("setup-locations." . $player->getName());
            $this->plugin->getConfig()->save();
            
            // Find which arena the player was setting up
            foreach ($this->plugin->getArenaManager()->getArenas() as $arena) {
                if ($arena->getStatus() === Arena::STATUS_SETUP) {
                    $arena->setSetupMode(false);
                    break;
                }
            }
        }
    }

    /**
     * Handle player move events
     */
    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $from = $event->getFrom();
        $to = $event->getTo();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena === null) {
            return;
        }
        
        // Handle player movement based on arena status
        switch ($arena->getStatus()) {
            case Arena::STATUS_WAITING:
                // Allow movement in lobby
                break;
                
            case Arena::STATUS_COUNTDOWN:
                // During countdown, teleport player back to spawn if they try to move horizontally
                // This allows them to still look around but not move from their position
                if ($from->getFloorX() !== $to->getFloorX() || $from->getFloorZ() !== $to->getFloorZ()) {
                    // Get player's spawn position with safe handling
                    $spawnPositions = $arena->getSpawnPositions();
                    $nearestSpawn = null;
                    $minDistance = PHP_FLOAT_MAX;
                    
                    foreach ($spawnPositions as $spawnPos) {
                        if (!isset($spawnPos["x"], $spawnPos["y"], $spawnPos["z"])) {
                            continue; // Skip invalid spawn positions
                        }
                        
                        $spawnX = $spawnPos["x"];
                        $spawnZ = $spawnPos["z"];
                        $distance = sqrt(
                            pow($spawnX - $player->getPosition()->getX(), 2) + 
                            pow($spawnZ - $player->getPosition()->getZ(), 2)
                        );
                        
                        if ($distance < $minDistance) {
                            $minDistance = $distance;
                            $nearestSpawn = $spawnPos;
                        }
                    }
                    
                    if ($nearestSpawn !== null) {
                        // Teleport player back to their nearest spawn position
                        $safeY = max(1, min(319, $nearestSpawn["y"])); // Ensure safe Y coordinate
                        $player->teleport(new \pocketmine\world\Position(
                            $nearestSpawn["x"],
                            $safeY,
                            $nearestSpawn["z"],
                            $player->getWorld()
                        ));
                    }
                }
                break;
            
            case Arena::STATUS_PLAYING:
                // Check if player fell into void
                if ($to->getY() < 0) {
                    $arena->removePlayer($player);
                    $player->sendMessage(TF::RED . "You fell into the void and were eliminated!");
                    
                    // Play elimination sound for other players
                    foreach ($arena->getPlayers() as $p) {
                        if ($p->getName() !== $player->getName()) {
                            $p->getWorld()->addSound($p->getPosition(), new \pocketmine\world\sound\BlazeShootSound());
                        }
                    }
                    
                    // Check if there's only one player left (winner) - safe handling
                    if (count($arena->getPlayers()) === 1) {
                        $players = $arena->getPlayers();
                        $winner = reset($players); // More reliable than array_values
                        
                        if ($winner instanceof Player && $winner->isOnline()) {
                            $arena->broadcastTitle(TF::GOLD . $winner->getName() . TF::GREEN . " wins!");
                            $arena->broadcastMessage(TF::GOLD . $winner->getName() . TF::GREEN . " has won the game!");
                            
                            // Play victory sound
                            $winner->getWorld()->addSound($winner->getPosition(), new \pocketmine\world\sound\LevelUpSound());
                        }
                        
                        // End the game regardless of winner state
                        $arena->endGame();
                    }
                } else {
                    // Remove block under player when they move
                    $position = $player->getPosition();
                    $world = $position->getWorld();
                    
                    // Get block below player
                    $blockPos = $position->subtract(0, 1, 0)->floor();
                    $block = $world->getBlock($blockPos);
                    
                    // Don't remove air blocks
                    if (!$block->isSameType(\pocketmine\block\VanillaBlocks::AIR())) {
                        // Replace with air
                        $world->setBlock($blockPos, \pocketmine\block\VanillaBlocks::AIR());
                        
                        // Play sound effect for block breaking
                        $world->addSound($blockPos, new \pocketmine\world\sound\BlockBreakSound($block));
                    }
                }
                break;
        }
    }

    /**
     * Handle player death events
     */
    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena !== null) {
            // Prevent item and XP drops
            $event->setDrops([]);
            $event->setXpDropAmount(0);
            
            // Store player data for safe removal after respawn
            $playerName = $player->getName();
            $arenaName = $arena->getName();
            
            // Use a slightly longer delay to ensure player is fully respawned
            $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
                function() use ($playerName, $arenaName): void {
                    $plugin = TnTRun::getInstance();
                    $arena = $plugin->getArenaManager()->getArena($arenaName);
                    
                    // Make sure both arena and player still exist
                    if ($arena === null) {
                        return;
                    }
                    
                    $player = \pocketmine\Server::getInstance()->getPlayerExact($playerName);
                    if ($player === null || !$player->isOnline()) {
                        return;
                    }
                    
                    // Check if player is still in the arena (might have been removed by another event)
                    if (isset($arena->getPlayers()[$playerName])) {
                        $arena->removePlayer($player);
                        $player->sendMessage(TF::RED . "You died and were eliminated!");
                        
                        // Play elimination sound for other players
                        foreach ($arena->getPlayers() as $p) {
                            if ($p->getName() !== $playerName) {
                                $p->getWorld()->addSound($p->getPosition(), new \pocketmine\world\sound\BlazeShootSound());
                            }
                        }
                        
                        // Check if there's only one player left (winner)
                        if (count($arena->getPlayers()) === 1) {
                            $players = $arena->getPlayers();
                            $winner = reset($players);
                            
                            if ($winner instanceof Player && $winner->isOnline()) {
                                $arena->broadcastTitle(TF::GOLD . $winner->getName() . TF::GREEN . " wins!");
                                $arena->broadcastMessage(TF::GOLD . $winner->getName() . TF::GREEN . " has won the game!");
                                
                                // Play victory sound
                                $winner->getWorld()->addSound($winner->getPosition(), new \pocketmine\world\sound\LevelUpSound());
                            }
                            
                            // End the game
                            $arena->endGame();
                        }
                    }
                }
            ), 5); // Increased delay from 1 to 5 ticks for more reliable respawn handling
        }
    }

    /**
     * Handle entity damage events
     */
    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        
        if (!$entity instanceof Player) {
            return;
        }
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($entity);
        if ($arena === null) {
            return;
        }
        
        // Cancel fall damage in all arena states
        if ($event->getCause() === EntityDamageEvent::CAUSE_FALL) {
            $event->cancel();
            return;
        }
        
        // Handle damage based on arena status
        switch ($arena->getStatus()) {
            case Arena::STATUS_WAITING:
            case Arena::STATUS_COUNTDOWN:
            case Arena::STATUS_ENDING:
                // Cancel all damage in waiting, countdown, and ending phases
                $event->cancel();
                break;
            
            case Arena::STATUS_PLAYING:
                // Allow damage in playing phase, but prevent player vs player damage
                if ($event instanceof EntityDamageByEntityEvent) {
                    $damager = $event->getDamager();
                    if ($damager instanceof Player) {
                        $event->cancel();
                    }
                }
                break;
        }
    }

    /**
     * Handle block break events
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena !== null) {
            // Cancel block breaking in all arena states
            $event->cancel();
        }
    }

    /**
     * Handle block place events
     */
    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        
        // Check if player is in an arena
        $arena = $this->getPlayerArena($player);
        if ($arena !== null) {
            // Cancel block placing in all arena states
            $event->cancel();
        }
    }

    /**
     * Get the arena a player is in
     */
    private function getPlayerArena(Player $player): ?Arena {
        foreach ($this->plugin->getArenaManager()->getArenas() as $arena) {
            $players = $arena->getPlayers();
            if (isset($players[$player->getName()])) {
                return $arena;
            }
        }
        
        return null;
    }
}