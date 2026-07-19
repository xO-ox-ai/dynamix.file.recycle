# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows a calver scheme: `YYYY.MM.DD{a,b,c,...}`.

## [Unreleased]

_Nothing yet._

## [2026.07.20a] - 2026-07-20

### Fixed

- Restricted `RecycleInject.page` to the official `/Main/Browse` route before
  loading Bootstrap, configuration, CSS, JavaScript or the runtime object.
  Main/Unassigned Devices and every other WebGUI page do not load recycle
  runtime code or assets.
- Kept `/mnt/disks`, `/mnt/remotes`, USB and other unsupported file-browser
  locations visible while disabling only the Recycle action.

### Changed

- Added an explicit second-stage confirmation dialog after all backend
  inspections pass. Its bounded scrollable list shows every canonical source
  path and the authoritative destination `.RecycleBin` folder; cancellation
  sends no recycle request.
- Moved Recycle immediately before DFM's native Delete button.

## [2026.07.19m] - 2026-07-19

### Added
- Added server-side Recycle Bin pagination with 50 rows by default, selectable
  25/50/100/200 page sizes, and navigation at the upper and lower right.
- Added ascending/descending sorting by item name, deletion time or size.
- Added current-page batch Restore and Permanently Delete controls below the
  table, plus an actionable-row Select All checkbox.
- Added links from restored records to their existing location in Unraid's
  official built-in Dynamix File Manager.
- Added current plugin file-log size and the number of restored/purged history
  rows that the History cleanup button will remove.
- Added a bilingual Plugin Manager description that follows the WebGUI's HTML
  language, using the `README.md` path read by Unraid's plugin list.

### Changed
- Replaced raw purge reason codes in the last column with expanded localized
  explanations and added a state guide below the table.
- Known unsupported DFM locations such as `/mnt/user*`, `/mnt/cache*`,
  `/mnt/disks`, `/mnt/remotes` and `/boot` now keep Recycle disabled even when
  files are selected.
- Expanded English and Chinese documentation for official DFM-only scope,
  on-demand `.RecycleBin` creation, exact dataset ownership, manual deletion,
  retained uninstall settings and reinstall behavior.

## [2026.07.19l] - 2026-07-19

### Fixed
- Restored saved managed-volume selections by reversing plugin INI escaping
  after `INI_SCANNER_RAW` parsing. Existing incorrectly read configurations
  recover automatically without manual file edits.
- Replaced the unavailable PDO SQLite driver with Unraid's bundled `sqlite3`
  command-line client. Values are bound as syntax-safe hex literals and the
  process is launched with an argv array rather than through a shell.
- Corrected PHP CLI discovery to prefer `/usr/bin/php` on Unraid 7.3 while
  retaining `/usr/local/bin/php` compatibility for older installations.
- Corrected diagnostics to inspect the actual Unraid PHP binary.

### Tested
- Added a real SQLite 3.53.3 integration test covering schema creation,
  parameterized path round trips, inserts, updates, deletes and integer limits.
- Added a configuration save/reload regression test for volume JSON.

## [2026.07.19k] - 2026-07-19

### Added
- Added a downloadable diagnostic-log bundle with plugin logs, configuration,
  PHP/PDO capabilities, ZFS/mount/block-device state and per-volume SQLite
  integrity/state snapshots.
- Added detailed DEBUG stages around inspection, history initialization,
  destination creation, filesystem checks, pending-row insertion, rename and
  finalization.

### Fixed
- Failed non-mutating recycle attempts now preserve DFM selection and restore
  the Recycle button instead of refreshing the list.
- Wrapped cleanup controls in a dedicated flex row and constrained their inline
  size, overriding Unraid's grid-item stretch behavior without shortening text.

## [2026.07.19j] - 2026-07-19

### Fixed
- Reused the parent `diskN` physical-device mapping when validating ZFS child
  datasets such as `/mnt/disk1/TV_series`, restoring the missing tree level.
- Added regression coverage proving that the longest matching dataset owns the
  recycle root, including `/mnt/nvme_system/appdata/.RecycleBin`.
- Constrained the cleanup buttons to their content width while retaining their
  explicit plugin-log and deleted-item-history labels.

## [2026.07.19i] - 2026-07-19

### Fixed
- Distinguished explicitly unsupported `/mnt/cache*` paths from independent
  named local storage pools. Verified ZFS volumes such as `/mnt/nvme_system`
  and their datasets remain eligible.
- Retained the `disks.ini` array-device mapping from `2026.07.19h`, so internal
  `/mnt/diskN` volumes are accepted without treating `/dev/mdN` as the physical
  backing device.

## [2026.07.19h] - 2026-07-19

### Fixed
- Registered Settings as a tile inside **User Programs** instead of rendering
  the page body as a standalone Settings section.
- Moved log and inactive-history cleanup into their corresponding settings
  sections and clarified that log cleanup affects this plugin only.
- Resolved `/mnt/diskN` through Unraid's `disks.ini` before checking the real
  device transport, so valid internal array disks are no longer filtered out.
- Excluded named Unraid cache/pool mountpoints and their ZFS child datasets from
  the supported-volume tree.

## [2026.07.19g] - 2026-07-19

### Added
- Added a static API-backed Recycle Bin details page with restore and permanent
  delete actions under **Tools -> Disk Utilities**.
- Added safe controls to clear diagnostic/event logs and to remove only
  restored or purged history rows.
- Added explanations for retention, capacity, SQLite VACUUM and metadata
  preservation settings, plus hierarchical array/ZFS volume selection.

### Changed
- Moved the settings tile into Unraid's **User Programs** section and reserved
  the Disk Utilities tile for recycle details.
- Keyed DFM assets by plugin version and remove legacy cached row controls on
  startup, while retaining the native batch action after Delete.

## [2026.07.19f] - 2026-07-19

### Changed
- Rebuilt Settings using the proven USB Guardian pattern: a static page shell
  loads and saves all configuration through a dedicated JavaScript API client.
- Replaced dangerous per-row trash controls with one native-style DFM batch
  action immediately to the right of Delete. DFM itself controls its enabled
  state from the current row selection.

### Fixed
- Corrected the `boot()` function import in `api.php`, which previously used a
  class import and prevented every API action from starting.
- Added validated supported-volume and status data to `config_get`, localized
  asynchronous settings states, and package checks for the new settings assets.

## [2026.07.19e] - 2026-07-19

### Fixed
- Replaced every runtime `__DIR__` reference in `.page` files with the
  installed plugin's absolute path. Unraid evaluates page bodies from
  `DefaultPageLayout`, so `__DIR__` previously resolved outside the plugin and
  prevented settings, localization and DFM assets from loading.
- Added a contract check that rejects `__DIR__` in every Unraid `.page` file.

## [2026.07.19d] - 2026-07-19

### Fixed
- Restored the settings tile while keeping the same management page available
  under **Tools -> Disk Utilities**.
- Corrected the Plugin Manager launch value from protocol-relative
  `//Tools/DynamixFileRecycle` to `/Tools/DynamixFileRecycle` and declared the
  native recycle icon.
- Replaced the global translation-cache dependency with a guarded plugin-local
  catalog merge so menu titles follow the active Unraid session language.
- Moved each DFM recycle control into the always-visible name column; narrower
  layouts no longer hide it with DFM's responsive action columns.
- Added visible page-load errors, persistent UI-injection diagnostics and
  browser-console markers instead of failing as an unexplained black page.

## [2026.07.19c] - 2026-07-19

### Fixed
- Moved the plugin's only visible page into **Tools -> Disk Utilities**, using
  Unraid's bundled recycle icon and the active WebGUI language.
- Removed the non-portable custom session-role check that could leave the
  settings and Recycle Bin pages blank on Unraid 7.3.2. Authentication and
  CSRF validation remain enforced by the Unraid WebGUI request pipeline.
- Made DFM asset loading independent of brittle server-side page detection,
  added Unraid cache busting, and replaced the CSP-sensitive SVG mask with the
  bundled Font Awesome trash icon.
- Removed the duplicate Tools/About entries and the plugin language override;
  English and Simplified Chinese now follow the system-selected language.

## [2026.07.19b] - 2026-07-19

### Changed
- Corrected the Slackware package root and explicit Plugin Manager
  install/remove lifecycle.
- Switched the update URL to the stable raw-main PLG and package verification
  from MD5 to SHA-256.
- Reworked DFM 7.3.2 integration to decorate official `table.indexer` rows
  before render, keeping a fixed button present through all async states.
- Restricted the release to verified internal array paths and local ZFS
  datasets. User shares, cache/pools, UD/external mounts, remote paths, the
  boot USB, USB/removable media, unknown topology and cross-filesystem moves
  now fail before confirmation with localized reason and advice.
- Added a validated per-disk/per-dataset management allowlist in Settings.
- Replaced the volatile central database with one SQLite shard inside every
  supported volume or dataset's `.RecycleBin`.

### Security
- Made every API action administrator-only, POST-only and action-whitelisted.
- Added a signed two-step inspection token bound to path, inode and metadata.
- Added operation locking, transitional database states, interruption
  recovery, non-overwriting restore, and tombstone-based purge.
- Added bounded persistent error audit logging on the Unraid boot device.

### Added
- Community Applications profile/wrapper metadata and a security policy.
- Source, localization, package-layout and checksum contract checks.

## [2026.07.19a] — 2026-07-19

First public release.

### Added
- "Move to Recycle Bin" button injected into the Dynamix File Manager Browse
  page via the official `Menu='Buttons'` channel — **no Unraid core file is
  modified**.
- Per-volume `.RecycleBin` directory for `/mnt/disk*` data drives and ZFS
  datasets. `/mnt/user` and `/mnt/cache*` are intentionally out of scope for
  this version.
- Original owner/group/mode preserved on every recycled item so restores are
  byte-identical.
- **Two-step recycle protocol with cross-filesystem confirmation.** When the
  source and its destination `.RecycleBin` live on different filesystems, the
  server returns `202 need_confirm` with the item size; the front-end shows a
  warning dialog and only proceeds with `confirm=1` after the user explicitly
  opts in. Same-filesystem moves remain atomic and require no extra prompt.
- SQLite history store (WAL mode) with `state` field
  (`active | restored | purged`) so Tools → Recycle Bin can show both current
  and historical items.
- Settings page (English + 中文) covering: feature toggle, log level, log
  retention, age-based eviction, capacity threshold eviction (percent or
  absolute, LRU), scheduled cleanup interval, optional auto-empty cron,
  history toggle, language picker.
- Tools → Recycle Bin browser page for browsing / restoring / purging /
  batch-selecting items.
- `scripts/recycle-maintain` invoked by Unraid cron (every hour, throttled
  by `interval_hours`) for age + capacity + log + history maintenance.
- CSRF protection delegated to Unraid's `auto_prepend`; the plugin never
  re-implements CSRF.
- Bilingual UI and documentation (English + 中文) following the Unraid system
  language.
- Build script (`tools/build.sh`) that produces the `.txz` package and
  patches the `.plg` MD5 placeholder for CA submission.

### Security
- All write operations require an authenticated admin session.
- All paths are normalised and confined to their original volume; `..`
  traversal is rejected.
- Operations on the recycle bin itself (or any path inside it) are rejected.
- Front-end item tokens are HMAC-signed to prevent path forgery; the server
  always re-validates on every call.
