<?php

declare(strict_types=1);

namespace Renz\TnTRun\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat as TF;
use Renz\TnTRun\TnTRun;
use Renz\TnTRun\arena\Arena;
use Renz\TnTRun\forms\TnTRunForm;

class TnTRunCommand extends Command implements PluginOwned {
    /** @var TnTRun */
    private TnTRun $plugin;

    /** @var array */
    private array $setupMode = [];

    public function __construct(TnTRun $plugin) {
        parent::__construct("tntrun", "TnTRun minigame commands", "/tntrun", ["tr"]);
        $this->setPermission("tntrun.command");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game.");
            return false;
        }

        if (empty($args)) {
            // Check if FormAPI is available before opening forms
            if (!class_exists(\jojoe77777\FormAPI\SimpleForm::class)) {
                $sender->sendMessage(TF::RED . "Forms are not available. FormAPI plugin is required.");
                $sender->sendMessage(TF::YELLOW . "Use /tr help to see available commands.");
                return true;
            }
            
            // Open main form
            TnTRunForm::sendMainForm($sender);
            return true;
        }
        
        switch ($args[0]) {
            case "help":
                return $this->handleHelpCommand($sender);
                
            case "create":
                return $this->handleCreateCommand($sender, $args);
            
            case "setup":
                return $this->handleSetupCommand($sender, $args);
            
            case "setspawn":
                return $this->handleSetSpawnCommand($sender, $args);
            
            case "typeblock":
                return $this->handleTypeBlockCommand($sender, $args);
            
            case "setlobby":
                return $this->handleSetLobbyCommand($sender, $args);
            
            case "join":
                return $this->handleJoinCommand($sender, $args);
            
            case "leave":
                return $this->handleLeaveCommand($sender);
            
            case "vote":
                return $this->handleVoteCommand($sender);
            
            case "force-start":
                return $this->handleForceStartCommand($sender, $args);
            
            case "force-stop":
                return $this->handleForceStopCommand($sender, $args);
            
            case "force-clear":
                return $this->handleForceClearCommand($sender, $args);
            
            default:
                $sender->sendMessage(TF::RED . "Unknown command. Use /tr help for a list of commands.");
                return false;
        }
    }

    /**
     * Handle the create command
     */
    private function handleCreateCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 5) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun create <name> <world> <minPlayers> <maxPlayers>");
            return false;
        }

        $name = $args[1];
        $world = $args[2];
        $minPlayers = (int) $args[3];
        $maxPlayers = (int) $args[4];

        // Validate arena name (only alphanumeric characters allowed)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $name)) {
            $sender->sendMessage(TF::RED . "Arena name can only contain letters (a-z, A-Z) and numbers (0-9).");
            return false;
        }

        // Check if world exists
        if (!$this->plugin->getServer()->getWorldManager()->isWorldGenerated($world)) {
            $sender->sendMessage(TF::RED . "World '$world' does not exist.");
            return false;
        }
        
        // Check if world is loaded, if not try to load it
        if (!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($world)) {
            $sender->sendMessage(TF::YELLOW . "Attempting to load world '$world'...");
            if (!$this->plugin->getServer()->getWorldManager()->loadWorld($world)) {
                $sender->sendMessage(TF::RED . "Failed to load world '$world'. Make sure it exists and is valid.");
                return false;
            }
            $sender->sendMessage(TF::GREEN . "World '$world' loaded successfully.");
        }

        // Validate player counts
        if ($minPlayers < 1) {
            $sender->sendMessage(TF::RED . "Minimum players must be at least 1.");
            return false;
        }

        if ($maxPlayers < $minPlayers) {
            $sender->sendMessage(TF::RED . "Maximum players must be greater than or equal to minimum players.");
            return false;
        }

        // Create arena
        if ($this->plugin->getArenaManager()->createArena($name, $world, $minPlayers, $maxPlayers)) {
            $sender->sendMessage(TF::GREEN . "Arena '$name' created successfully!");
            $sender->sendMessage(TF::YELLOW . "Use /tntrun setup $name to set up the arena.");
            return true;
        } else {
            $sender->sendMessage(TF::RED . "Failed to create arena. It may already exist.");
            return false;
        }
    }

    /**
     * Handle the setup command
     */
    private function handleSetupCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun setup <name>");
            return false;
        }

        $name = $args[1];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        // Enter setup mode
        $arena->setSetupMode(true);
        $this->setupMode[$sender->getName()] = $name;

        // Save player's original location before teleporting
        $this->saveSetupPlayerLocation($sender);

        // Teleport to arena world
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($arena->getWorld());
        
        if ($world !== null) {
            $sender->teleport($world->getSpawnLocation());
            $sender->sendMessage(TF::GREEN . "You are now setting up arena '$name'.");
            $sender->sendMessage(TF::YELLOW . "Use /tntrun setspawn <name> <position> to set spawn positions.");
            $sender->sendMessage(TF::YELLOW . "Use /tntrun typeblock <name> <blockname> to set the block type.");
            $sender->sendMessage(TF::YELLOW . "Use /tntrun setlobby <name> <world> to set the lobby location.");
            
            // Play sound
            $sender->getWorld()->addSound($sender->getPosition(), new \pocketmine\world\sound\AnvilUseSound());
            
            return true;
        } else {
            $sender->sendMessage(TF::RED . "Failed to load arena world.");
            return false;
        }
    }
    
    /**
     * Save setup player's original location
     */
    private function saveSetupPlayerLocation(Player $player): void {
        $position = $player->getPosition();
        $this->plugin->getConfig()->set("setup-locations." . $player->getName(), [
            "world" => $position->getWorld()->getFolderName(),
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ(),
            "yaw" => $player->getLocation()->getYaw(),
            "pitch" => $player->getLocation()->getPitch()
        ]);
        $this->plugin->getConfig()->save();
    }
    
    /**
     * Restore setup player's original location
     */
    private function restoreSetupPlayerLocation(Player $player): void {
        $locationData = $this->plugin->getConfig()->get("setup-locations." . $player->getName());
        
        if ($locationData === null) {
            return;
        }
        
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($locationData["world"]);
        
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
        }
        
        // Remove the saved location
        $this->plugin->getConfig()->remove("setup-locations." . $player->getName());
        $this->plugin->getConfig()->save();
    }

    /**
     * Handle the setspawn command
     */
    private function handleSetSpawnCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 3) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun setspawn <name> <position>");
            return false;
        }

        $name = $args[1];
        $position = (int) $args[2];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        if ($position < 1 || $position > $arena->getMaxPlayers()) {
            $sender->sendMessage(TF::RED . "Position must be between 1 and " . $arena->getMaxPlayers() . ".");
            return false;
        }

        // Set spawn position
        $arena->setSpawnPosition($position - 1, $sender->getPosition(), $sender->getWorld());
        $this->plugin->getArenaManager()->saveArena($arena);

        $sender->sendMessage(TF::GREEN . "Spawn position $position set for arena '$name'.");
        return true;
    }

    /**
     * Handle the typeblock command
     */
    private function handleTypeBlockCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 3) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun typeblock <name> <blockname>");
            return false;
        }

        $name = $args[1];
        $blockType = $args[2];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        // Set block type
        $arena->setBlockType($blockType);
        $this->plugin->getArenaManager()->saveArena($arena);

        $sender->sendMessage(TF::GREEN . "Block type set to '$blockType' for arena '$name'.");
        return true;
    }

    /**
     * Handle the setlobby command
     */
    private function handleSetLobbyCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 3) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun setlobby <name> <world>");
            return false;
        }

        $name = $args[1];
        $worldName = $args[2];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        // Check if world exists
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($worldName);
        if ($world === null) {
            $sender->sendMessage(TF::RED . "World '$worldName' does not exist or is not loaded.");
            return false;
        }

        // Set lobby position
        $arena->setLobbyPosition($sender->getPosition(), $sender->getWorld());
        $this->plugin->getArenaManager()->saveArena($arena);

        $sender->sendMessage(TF::GREEN . "Lobby position set for arena '$name'.");
        return true;
    }

    /**
     * Handle the join command
     */
    private function handleJoinCommand(Player $sender, array $args): bool {
        if (count($args) > 1) {
            $name = $args[1];
            $arena = $this->plugin->getArenaManager()->getArena($name);

            if ($arena === null) {
                $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
                return false;
            }

            if ($arena->getStatus() !== Arena::STATUS_WAITING) {
                $sender->sendMessage(TF::RED . "Arena '$name' is not available for joining.");
                return false;
            }

            if ($arena->joinPlayer($sender)) {
                return true;
            } else {
                $sender->sendMessage(TF::RED . "Failed to join arena '$name'.");
                return false;
            }
        } else {
            // Join random arena
            $arena = $this->plugin->getArenaManager()->getRandomArena();

            if ($arena === null) {
                $sender->sendMessage(TF::RED . "No available arenas found.");
                return false;
            }

            if ($arena->joinPlayer($sender)) {
                return true;
            } else {
                $sender->sendMessage(TF::RED . "Failed to join arena.");
                return false;
            }
        }
    }

    /**
     * Handle the leave command
     */
    private function handleLeaveCommand(Player $sender): bool {
        // Find which arena the player is in
        foreach ($this->plugin->getArenaManager()->getArenas() as $arena) {
            $players = $arena->getPlayers();
            if (isset($players[$sender->getName()])) {
                $arena->removePlayer($sender);
                $sender->sendMessage(TF::GREEN . "You left the arena.");
                return true;
            }
        }

        $sender->sendMessage(TF::RED . "You are not in an arena.");
        return false;
    }

    /**
     * Handle the vote command
     */
    private function handleVoteCommand(Player $sender): bool {
        // Check if FormAPI is available before opening forms
        if (!class_exists(\jojoe77777\FormAPI\SimpleForm::class)) {
            $sender->sendMessage(TF::RED . "Forms are not available. FormAPI plugin is required for voting.");
            return true;
        }
        
        // Open vote form
        TnTRunForm::sendVoteForm($sender);
        return true;
    }

    /**
     * Handle the force-start command
     */
    private function handleForceStartCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun force-start <name>");
            return false;
        }

        $name = $args[1];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        if ($arena->getStatus() !== Arena::STATUS_WAITING) {
            $sender->sendMessage(TF::RED . "Arena '$name' is not in waiting state.");
            return false;
        }

        if (count($arena->getPlayers()) < $arena->getMinPlayers()) {
            $sender->sendMessage(TF::RED . "Arena '$name' needs at least " . $arena->getMinPlayers() . " players to start.");
            return false;
        }

        // Start countdown
        $arena->startCountdown();
        $sender->sendMessage(TF::GREEN . "Forced arena '$name' to start.");
        return true;
    }

    /**
     * Handle the force-stop command
     */
    private function handleForceStopCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun force-stop <name>");
            return false;
        }

        $name = $args[1];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        if ($arena->getStatus() !== Arena::STATUS_PLAYING && $arena->getStatus() !== Arena::STATUS_COUNTDOWN) {
            $sender->sendMessage(TF::RED . "Arena '$name' is not active.");
            return false;
        }

        // End game
        $arena->endGame();
        $sender->sendMessage(TF::GREEN . "Forced arena '$name' to stop.");
        return true;
    }

    /**
     * Handle the force-clear command
     */
    private function handleForceClearCommand(Player $sender, array $args): bool {
        if (!$sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
            return false;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::RED . "Usage: /tntrun force-clear <name>");
            return false;
        }

        $name = $args[1];
        $arena = $this->plugin->getArenaManager()->getArena($name);

        if ($arena === null) {
            $sender->sendMessage(TF::RED . "Arena '$name' does not exist.");
            return false;
        }

        // Reset arena
        $arena->resetArena();
        $sender->sendMessage(TF::GREEN . "Forced arena '$name' to clear.");
        return true;
    }

    /**
     * Handle the help command
     */
    private function handleHelpCommand(Player $sender): bool {
        $sender->sendMessage(TF::GOLD . "=== TnTRun Help ===");
        
        // Basic commands (available to all players)
        $sender->sendMessage(TF::GREEN . "/tr" . TF::WHITE . " - Open the main TnTRun menu");
        $sender->sendMessage(TF::GREEN . "/tr help" . TF::WHITE . " - Show this help message");
        $sender->sendMessage(TF::GREEN . "/tr join [arena]" . TF::WHITE . " - Join a specific arena or random arena");
        $sender->sendMessage(TF::GREEN . "/tr leave" . TF::WHITE . " - Leave the current arena");
        $sender->sendMessage(TF::GREEN . "/tr vote" . TF::WHITE . " - Vote for a map");
        
        // Admin commands (only shown to players with admin permission)
        if ($sender->hasPermission("tntrun.admin")) {
            $sender->sendMessage(TF::YELLOW . "\n=== Admin Commands ===");
            $sender->sendMessage(TF::GREEN . "/tr create <name> <world> <minPlayers> <maxPlayers>" . TF::WHITE . " - Create a new arena");
            $sender->sendMessage(TF::GREEN . "/tr setup <name>" . TF::WHITE . " - Enter setup mode for an arena");
            $sender->sendMessage(TF::GREEN . "/tr setspawn <name> <position>" . TF::WHITE . " - Set a spawn position");
            $sender->sendMessage(TF::GREEN . "/tr typeblock <name> <blockname>" . TF::WHITE . " - Set the block type");
            $sender->sendMessage(TF::GREEN . "/tr setlobby <name> <world>" . TF::WHITE . " - Set the lobby location");
            $sender->sendMessage(TF::GREEN . "/tr force-start <name>" . TF::WHITE . " - Force start an arena");
            $sender->sendMessage(TF::GREEN . "/tr force-stop <name>" . TF::WHITE . " - Force stop an arena");
            $sender->sendMessage(TF::GREEN . "/tr force-clear <name>" . TF::WHITE . " - Force clear an arena");
        }
        
        return true;
    }

    /**
     * Get the plugin that owns this command
     */
    public function getOwningPlugin(): Plugin {
        return $this->plugin;
    }
}