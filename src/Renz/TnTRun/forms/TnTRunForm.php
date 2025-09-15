<?php

declare(strict_types=1);

namespace Renz\TnTRun\forms;

use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use Renz\TnTRun\TnTRun;
use Renz\TnTRun\arena\Arena;

class TnTRunForm {
    /**
     * Send the main TnTRun form to a player
     */
    public static function sendMainForm(Player $player): void {
        $plugin = TnTRun::getInstance();
        
        // Play sound when opening form
        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\PopSound());
        
        $form = new \jojoe77777\FormAPI\SimpleForm(function (Player $player, ?int $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            
            // Play sound when clicking button
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());

            switch ($data) {
                case 0: // Play
                    self::sendJoinForm($player);
                    break;
                
                case 1: // Leave
                    // Find which arena the player is in
                    foreach ($plugin->getArenaManager()->getArenas() as $arena) {
                        $players = $arena->getPlayers();
                        if (isset($players[$player->getName()])) {
                            $arena->removePlayer($player);
                            $player->sendMessage(TF::GREEN . "You left the arena.");
                            return;
                        }
                    }
                    
                    $player->sendMessage(TF::RED . "You are not in an arena.");
                    break;
                
                case 2: // Vote
                    self::sendVoteForm($player);
                    break;
            }
        });

        $form->setTitle(TF::BOLD . TF::GOLD . "TnTRun");
        $form->setContent(TF::YELLOW . "Welcome to TnTRun! Select an option:");
        
        $form->addButton(TF::GREEN . "Play", 0, "textures/items/diamond");
        $form->addButton(TF::RED . "Leave", 0, "textures/items/redstone_dust");
        $form->addButton(TF::AQUA . "Vote", 0, "textures/items/paper");
        
        $player->sendForm($form);
    }

    /**
     * Send the join arena form to a player
     */
    public static function sendJoinForm(Player $player): void {
        $plugin = TnTRun::getInstance();
        $arenas = $plugin->getArenaManager()->getArenas();
        
        if (empty($arenas)) {
            $player->sendMessage(TF::RED . "No arenas available.");
            // Play error sound
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\AnvilFallSound());
            return;
        }
        
        // Play sound when opening form
        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\PopSound());
        
        $form = new \jojoe77777\FormAPI\SimpleForm(function (Player $player, ?int $data) use ($plugin, $arenas) {
            if ($data === null) {
                return;
            }
            
            // Play sound when clicking button
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
            
            $arenaNames = array_keys($arenas);
            $arenaName = $arenaNames[$data];
            $arena = $arenas[$arenaName];
            
            if ($arena->getStatus() !== Arena::STATUS_WAITING) {
                $player->sendMessage(TF::RED . "This arena is not available for joining.");
                return;
            }
            
            if ($arena->joinPlayer($player)) {
                $player->sendMessage(TF::GREEN . "You joined arena " . $arenaName . "!");
            } else {
                $player->sendMessage(TF::RED . "Failed to join arena " . $arenaName . ".");
            }
        });
        
        $form->setTitle(TF::BOLD . TF::GOLD . "Join Arena");
        $form->setContent(TF::YELLOW . "Select an arena to join:");
        
        foreach ($arenas as $name => $arena) {
            $status = "Unknown";
            $playerCount = count($arena->getPlayers());
            $maxPlayers = $arena->getMaxPlayers();
            
            switch ($arena->getStatus()) {
                case Arena::STATUS_WAITING:
                    $status = TF::GREEN . "Waiting";
                    break;
                
                case Arena::STATUS_COUNTDOWN:
                    $status = TF::GOLD . "Starting";
                    break;
                
                case Arena::STATUS_PLAYING:
                    $status = TF::RED . "In Progress";
                    break;
                
                case Arena::STATUS_ENDING:
                    $status = TF::DARK_RED . "Ending";
                    break;
                
                case Arena::STATUS_SETUP:
                    $status = TF::GRAY . "Setup";
                    break;
            }
            
            $form->addButton(
                TF::YELLOW . $name . "\n" . $status . " - " . TF::WHITE . $playerCount . "/" . $maxPlayers,
                0,
                "textures/blocks/tnt_side"
            );
        }
        
        $player->sendForm($form);
    }

    /**
     * Send the vote form to a player
     */
    public static function sendVoteForm(Player $player): void {
        $plugin = TnTRun::getInstance();
        
        // Find which arena the player is in
        $playerArena = null;
        foreach ($plugin->getArenaManager()->getArenas() as $arena) {
            $players = $arena->getPlayers();
            if (isset($players[$player->getName()])) {
                $playerArena = $arena;
                break;
            }
        }
        
        if ($playerArena === null) {
            $player->sendMessage(TF::RED . "You are not in an arena.");
            // Play error sound
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\AnvilFallSound());
            return;
        }
        
        if ($playerArena->getStatus() !== Arena::STATUS_WAITING) {
            $player->sendMessage(TF::RED . "Voting is only available in the waiting phase.");
            // Play error sound
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\AnvilFallSound());
            return;
        }
        
        // Get all available arenas for voting
        $arenas = $plugin->getArenaManager()->getArenas();
        
        // Play sound when opening form
        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\PopSound());
        
        $form = new \jojoe77777\FormAPI\SimpleForm(function (Player $player, ?int $data) use ($plugin, $arenas, $playerArena) {
            if ($data === null) {
                return;
            }
            
            // Play sound when clicking button
            $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\ClickSound());
            
            $arenaNames = array_keys($arenas);
            $votedArenaName = $arenaNames[$data];
            
            if ($playerArena->addVote($player, $votedArenaName)) {
                $player->sendMessage(TF::GREEN . "You voted for " . $votedArenaName . "!");
            } else {
                $player->sendMessage(TF::RED . "Failed to vote for " . $votedArenaName . ".");
            }
        });
        
        $form->setTitle(TF::BOLD . TF::GOLD . "Vote for Map");
        $form->setContent(TF::YELLOW . "Select a map to vote for:");
        
        foreach ($arenas as $name => $arena) {
            $form->addButton(
                TF::YELLOW . $name,
                0,
                "textures/blocks/tnt_side"
            );
        }
        
        $player->sendForm($form);
    }
}