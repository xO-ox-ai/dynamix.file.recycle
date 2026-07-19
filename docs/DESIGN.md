# Design: Dynamix File Recycle Bin

This document describes the `2026.07.19g` architecture and its conservative
storage boundary.

## 1. Safety model

The batch action is not proof that selected paths are supported. The backend
makes the authoritative decision after the user clicks it.

An item is accepted only when all of these checks pass:

1. The path is absolute, canonical, exists, and is not a symlink or alias.
2. It belongs to `/mnt/diskN` or a discovered local ZFS dataset.
3. It is outside `/mnt/user*`, `/mnt/cache*`, `/mnt/disks`, `/mnt/remotes`,
   `/boot`, and other system-managed roots.
4. Every backing block device can be inspected and is neither USB nor marked
   removable. Unknown topology is rejected.
5. The item is an ordinary file or directory, not a volume root, recycle-bin
   item, special node, or directory containing a nested mount.
6. Source and destination are on the same filesystem.
7. The owning disk or dataset is enabled in the saved per-volume allowlist.

This release never falls back to recursive copy-and-delete. A failed check is
non-mutating and returns a stable error code so the browser can show localized
reason and recovery advice.

## 2. Front-end integration

`RecycleInject.page` uses Unraid's `Menu='Buttons:5'` page channel. It does not
patch `Browse.page`, `HeadInlineJS.php`, or another plugin.

The plugin inserts one `input.extra` control immediately after DFM's native
Delete control in `#buttons`. Reusing the native class means DFM's own
`selectOne()` and `selectAll()` logic enables or disables Recycle together with
the current checkbox selection. No per-row destructive control is added.

Selected paths come from the official `check_N` to `row_N[data][type]`
mapping. The plugin validates all selected paths before showing one batch
confirmation. Mutations then run sequentially to avoid SQLite lock contention.

DFM asset URLs include the plugin version in addition to Unraid's `autov`
value. The current script also removes legacy per-row controls, so an upgrade
cannot leave an old cached trash icon beside item names.

Settings and recycle details are separate static page shells. Settings is
registered under User Programs (`Menu="Settings"`); recycle details is under
Tools -> Disk Utilities. Both load storage state through `api.php` after the
page has rendered, so backend failures remain visible as inline errors.

## 3. Request flow

```text
DFM checkbox selection + bottom Recycle action
  -> POST inspect(path, csrf_token) for every selected item
  -> admin + scope + topology + inode checks
  <- inspection_token(path, dev, ino, mode, size, mtime, ctime, expiry)
  -> one user confirmation after all inspections succeed
  -> sequential POST recycle(path, inspection_token, csrf_token)
  -> repeat all checks and verify the signed current inode state
  -> pending database row
  -> atomic rename into .RecycleBin
  -> active database row + operation event
```

All API actions are POST-only, limited in body size and restricted to an
explicit action whitelist. Unraid's `local_prepend.php` performs native CSRF
validation before `api.php`; the plugin supplies the current token but does
not implement a parallel CSRF scheme. Every action additionally requires an
administrator session.

## 4. Per-volume persistence

There is no centralized item database and no JSON-to-SQLite conversion.

```text
<supported-volume>/.RecycleBin/
  .dynamix-file-recycle.sqlite
  original/path/file.__recycle_<uuid>
```

Each supported array disk or ZFS dataset owns one SQLite shard. This keeps the
metadata on the same persistence boundary as the recycled data. Global views
open the shards on currently available, verified volumes and merge their
results in memory.

SQLite uses WAL mode, full synchronous writes and a busy timeout. Item states
are `pending`, `active`, `restoring`, `restored`, `purging`, and `purged`.
Transitional states are written before filesystem mutations. Opening a shard
reconciles interrupted transitions against the source, destination and purge
tombstone paths.

## 5. Filesystem operations

Recycle uses a same-filesystem `rename()` into a UUID-suffixed destination.
Restore reserves a non-overwriting target and uses another atomic rename.
Purge first renames the tracked item to a hidden tombstone and then deletes the
tombstone without following symlinks. Empty-bin operations iterate tracked
active rows; they do not sweep arbitrary files placed manually in the bin.

A process-wide non-blocking `flock` serializes recycle, restore, purge and
maintenance operations. Busy operations fail visibly rather than interleave.

## 6. Maintenance and logging

There is no unconditional hourly or daily maintenance job. When the user saves
a cleanup expression, `Scheduler` writes exactly one plugin-owned `.cron` file
under `/boot/config/plugins/dynamix.file.recycle/` and calls Unraid's official
`update_cron` helper. An empty expression removes the entry. The scheduled run
empties enabled recycle bins and performs age/capacity reconciliation, event
and history retention, interruption recovery and optional SQLite VACUUM in the
same operation. Manual maintenance remains an explicit API/CLI action and
never performs the scheduled full empty.

Runtime logs live under
`/usr/local/emhttp/state/plugins/dynamix.file.recycle/logs/` and are expected
to disappear at reboot. Errors are copied to the bounded persistent audit log
under `/boot/config/plugins/dynamix.file.recycle/logs/`. Operation events are
also recorded in the owning volume's SQLite shard.

Uninstall preserves `.RecycleBin`, its database, settings and persistent audit
logs. It removes only plugin code, scheduled tasks, runtime logs and locks.

## 7. Per-volume policy

The default `volumes.allowed = "*"` applies only until the Settings page is
saved. The page enumerates `FsInspector::supportedVolumes()` and submits the
checked roots. The backend revalidates every submitted root and stores a JSON
array; an unsupported or stale selection rejects the entire save.

An unchecked volume remains queryable from the hidden Recycle Bin view so existing data
does not disappear from view. Recycler, restorer, purger and maintenance all
enforce the allowlist before mutating it.

## 8. Package and CA layout

The Slackware package contains top-level `install/` and `usr/` paths, so it
extracts the plugin to `/usr/local/emhttp/plugins/dynamix.file.recycle`.
`dynamix.file.recycle.plg` has explicit install/remove methods, downloads a
versioned package to `/boot/config/plugins/dynamix.file.recycle`, verifies
SHA-256, and exposes a stable raw-main `pluginURL` for update discovery.

Community Applications metadata is provided by `ca_profile.xml` and
`plugins/dynamix-file-recycle.xml`.

## 9. Known limitations

- No `/mnt/user`, cache/pool, UD, remote, boot-device or USB workflow.
- No cross-filesystem recycle or restore.
- No symbolic-link or nested-mount handling.
- DFM and Unraid 7.3.2 are the initial compatibility target.
- Software can detect USB/removable transport but cannot always prove a disk's
  physical enclosure. eSATA is the common ambiguous case.
- A volume that is offline is absent from the global history view until it is
  mounted and passes topology checks again.
