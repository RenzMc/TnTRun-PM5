# TnTRun

A TnTRun minigame plugin for PocketMine-MP 5.32.0.

## Description

TnTRun is a minigame where players run on a floor that disappears beneath them. The last player standing wins!

## Features

- Create and manage multiple arenas
- Customizable arena settings
- Player lobby system
- Map voting system
- Admin commands for arena management
- Form UI for easy navigation

## Commands

### Player Commands

- `/tntrun` or `/tr` - Opens the main TnTRun form
- `/tr join [arena]` - Join a specific arena or a random one if no arena is specified
- `/tr leave` - Leave the current arena
- `/tr vote` - Vote for a map

### Admin Commands
- `/tr create <name> <world> <minPlayers> <maxPlayers>` - Create a new arena  
- `/tr setup <name>` - Enter setup mode for an arena  
- `/tr setspawn <name> <position>` - Set a spawn position  
- `/tr typeblock <name> <blockname>` - Set the block type  
- `/tr setlobby <name> <world>` - Set the lobby location  
- `/tr setup-complete <name>` - Complete arena setup and set to ready  
- `/tr setup-exit` - Exit setup mode without saving  
- `/tr force-start <name>` - Force start an arena  
- `/tr force-stop <name>` - Force stop an arena  
- `/tr force-clear <name>` - Force clear an arena

## Permissions

- `tntrun.command` - Allow use of basic TnTRun commands
- `tntrun.admin` - Allow use of admin commands

## Installation

1. Download the plugin
2. Place it in your server's `plugins` folder
3. Restart your server
4. Configure arenas using the commands

## Arena Setup Guide

1. Create an arena: `/tr create <name> <world> <minPlayers> <maxPlayers>`
2. Enter setup mode: `/tr setup <name>`
3. Set spawn positions: `/tr setspawn <name> <position>` (set multiple spawn positions based on max players)
4. Set block type: `/tr typeblock <name> <blockname>` (e.g., tnt, sand, gravel)
5. Set lobby (optional): `/tr setlobby <name> <world>`

## Game Flow

1. Players join the arena using `/tr join`
2. When enough players join, a countdown starts
3. The game begins and blocks disappear as players walk on them
4. Players who fall are eliminated
5. The last player standing wins

## Configuration

The plugin's configuration file (`config.yml`) allows you to customize various aspects of the game:

- Countdown times
- Messages
- Form UI text
- Item settings

## Author

- Renz

## Version

1.0.1

## API

5.32.0

## Requirements

- PocketMine-MP 5.32.0 or newer
- PHP 8.2 or newer
- FormAPI plugin (optional, for GUI forms)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed list of changes.
