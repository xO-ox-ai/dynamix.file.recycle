# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows a calver scheme: `YYYY.MM.DD{a,b,c,...}`.

## [Unreleased]

_Nothing yet._

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
