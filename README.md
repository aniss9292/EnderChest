# EnderChest

A PocketMine-MP legacy plugin (API 1.0.0 / 2.0.0) that adds personal, world-shared Ender Chest storage with an upgrade path, crafting recipe, and visual particle effects. Designed for MCPE 0.14.3 / 0.15.10 servers running on Genisys-style forks.

## What It Does

- **Personal Ender Inventory**: Each player gets a private 27-slot inventory (upgradable to 54 slots) that is shared across all worlds by UUID. Access it by right-clicking any registered Ender Chest block.
- **Upgrade System**: Players can upgrade from a single chest (27 slots) to a double chest (54 slots) for $500,000 via an in-game upgrade GUI.
- **Crafting Recipe**: Craft the custom Ender Chest item using 8 Obsidian surrounding 1 Chest (same pattern as vanilla).
- **Block Registration**: Placed custom chest blocks are tracked per-world; breaking them drops the custom item back.
- **Visual Effects**: Portal particles spawn from all 6 faces of registered blocks when players are nearby.
- **Anti-Dupe Protection**: GUI items are tagged with an NBT marker; transactions involving them are cancelled to prevent item duplication on legacy MCPE clients.

## Requirements

| Component | Requirement |
|---|---|
| PocketMine-MP | API 1.0.0 or 2.0.0 (Genisys / legacy forks) |
| PHP | 7.0+ (pre-7.1 safe syntax) |
| Minecraft PE | 0.14.3 / 0.15.10 |
| Soft Dependency | EconomyAPI (required for upgrade payments) |

> **Note**: This plugin targets the legacy PocketMine-MP era (`pocketmine\Level`, `pocketmine\Player extends Human`, NBT tags with named constructor arguments). It is **not** compatible with modern PocketMine-MP 4.x / 5.x (`pocketmine\world\World`, unnamed NBT tags, PHP 8+).

## Installation

1. Download or build the plugin folder (`EnderChest/`).
2. Place the folder into your server's `plugins/` directory.
3. Restart the server.
4. Ensure `EconomyAPI` is installed if you want upgrade functionality.

The plugin creates its data folder (`plugins/EnderChest/`) automatically with:
- `enderchests.yml` — per-player inventory data (base64-encoded NBT items)
- `enderchest_blocks.yml` — registered block positions per world

## Commands

No commands are registered. All interaction is through block placement and right-click.

## Permissions

No permission nodes are declared in `plugin.yml`. The plugin relies on physical block interaction rather than command-based access control.

## Usage

### Crafting

```
OOO
OCO
OOO
```

- `O` = Obsidian
- `C` = Chest

The result is a custom `Ender Chest` item (purple display name) that can be placed in the world.

### Accessing Your Inventory

1. Place the custom Ender Chest block in the world.
2. Right-click the block to open your personal inventory.
3. The inventory is shared globally — opening it from any registered block shows the same contents.

### Upgrading

1. Right-click the block while **sneaking** to open the upgrade GUI.
2. Click the green wool "Confirm Upgrade" button.
3. $500,000 is deducted via EconomyAPI.
4. Your inventory size increases from 27 to 54 slots.
5. The button turns red ("Already Upgraded") after a successful upgrade.

### Breaking the Block

Break a registered Ender Chest block to unregister it and receive the custom item back.

## Configuration

No user-editable `config.yml` is provided. Key constants are defined in code:

| Constant | Value | Description |
|---|---|---|
| `SIZE_DEFAULT` | 27 | Default inventory slots |
| `SIZE_UPGRADED` | 54 | Upgraded inventory slots |
| `UPGRADE_PRICE` | 500000 | Upgrade cost |

Data files (`enderchests.yml`, `enderchest_blocks.yml`) are managed automatically and should not be edited manually.

## Architecture

### Main Components

- **`Main`** — Plugin entry point. Manages the manager, block registry, particle task, and GUI tracking (`$activeGUI`, `$openGuiType`).
- **`EnderChestManager`** — Handles player data persistence (load/save items, upgrade status) using YAML + base64-encoded NBT.
- **`EnderChestBlockRegistry`** — Tracks registered block positions per world (`levelName` → `x:y:z`).
- **`EnderChestListener`** — Event listener for block place/break/interact, inventory transactions, and inventory close.
- **`EnderChestItemFactory`** — Creates the custom item, defines its NBT display name, and registers the crafting recipe.
- **`EnderBaseGUI`** / `PersonalEnderChestGUI` / `UpgradeGUI` — Fake-chest GUI system using `CustomInventory`, `FakeHolder`, and raw network packets (`UpdateBlockPacket`, `BlockEntityDataPacket`, `ContainerOpenPacket`).

### Key Design Patterns

- **NBT Serialization (v2)**: Items are serialized by writing `__ec_id`, `__ec_damage`, and `__ec_count` as sibling keys inside the item's own `CompoundTag` (no nested `"tag"` child). This avoids tag-renaming issues that caused custom NBT (e.g., SmartSpawner data) to be lost on reload.
- **Anti-Dupe**: Every GUI item receives an `enderchest_menu_item` NBT byte tag. The inventory transaction listener cancels any transaction involving tagged items.
- **Stale Window Fix**: Before opening a new GUI, `closeActiveGui()` removes any previously tracked window to prevent `addWindow()` failures caused by stale client/server window state.
- **Deferred Tasks**: `BlockRegisterTask` (2 ticks after placement) and `SaveInventoryTask` (1 tick after transaction) replace `ClosureTask` (unavailable on API 2.0.0).

### Data Flow

1. Player places custom item → `BlockPlaceEvent` → deferred `BlockRegisterTask` registers position.
2. Player right-clicks registered block → `PlayerInteractEvent` → opens `PersonalEnderChestGUI` or `UpgradeGUI`.
3. Player interacts with GUI → `InventoryTransactionEvent` → saves inventory (deferred) or processes upgrade.
4. Player closes GUI → `InventoryCloseEvent` → saves contents, clears tracking.
5. Player breaks block → `BlockBreakEvent` → unregisters position, drops custom item.

## Compatibility Notes

- **EconomyAPI**: Required for upgrades. The plugin fetches it directly via `PluginManager` (not through a deprecated `getProvider()` method) for compatibility with EconomyAPI v2.0.9.
- **NBT Tags**: Uses named constructor arguments (`new StringTag("Name", $value)`) consistent with the legacy API era.
- **Particles**: `PortalParticle` is spawned from all 6 block faces with a small jitter, matching vanilla Ender Chest behavior.
- **World Names**: Block registry uses `Level::getFolderName()` (folder name on disk) rather than `Level::getName()` (internal `level.dat` name) to avoid mismatches.

## Development Notes

- The plugin uses `PluginTask` (not generic `Task`) for all scheduled work.
- `Config::save()` is never called inside high-frequency loops; saves are deferred or triggered by events.
- Player names are handled via UUID (`getUniqueId()->toString()`), not string names, ensuring consistency regardless of case.
- The `getEconomyProvider()` method is deprecated but retained for backward compatibility; new code should fetch `EconomyAPI` directly from the plugin manager.

## License

Not specified in plugin metadata. Check the repository for licensing details.

---

*Built for PocketMine-MP API 2.0.0 / Genisys forks, MCPE 0.14.3 / 0.15.10, PHP 7.0.*
