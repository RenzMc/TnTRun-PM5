# TnTRun Plugin Changelog

## Version 1.0.1 (PocketMine-MP 5.32.0 Compatibility Update)

### Fixed
- Updated GameMode usage to be compatible with PHP 8.2 enums
  - Changed `GameMode::ADVENTURE()` to `GameMode::ADVENTURE`
  - Replaced `GameMode::fromInt()` with a proper switch statement for gamemode conversion
  - Added proper gamemode ID storage in player data

### Improved
- Enhanced player death handling logic
  - Added more robust checks for player and arena existence
  - Increased respawn handling delay for more reliable operation
  - Added additional sound effects for player elimination
  - Improved winner detection after player death

### Added
- Better error handling throughout the plugin
  - Added PHP version compatibility check
  - Improved error handling in onEnable and onDisable methods
  - Added more specific error messages for troubleshooting
  - Added proper plugin disabling when critical errors occur

### Updated
- Plugin metadata in plugin.yml
  - Added mcpe-protocol version for Minecraft 1.21.100
  - Added minimum PHP version requirement (8.2.0)
  - Updated plugin version to 1.0.1

## Version 1.0.0 (Initial Release)

- Initial release of TnTRun minigame plugin