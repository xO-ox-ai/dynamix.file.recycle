# Dynamix File Recycle Bin

> Chinese documentation: [README.zh.md](README.zh.md)

A guarded recycle bin for the Unraid Dynamix File Manager (DFM). It adds a
selection-aware **Recycle** action immediately after DFM's native Delete
button and atomically renames selected files or directories into each owning
volume's `.RecycleBin`.

The current release deliberately supports only simple, verifiable storage
layouts. The server checks every selected item before confirmation and shows a
specific reason and advice when any path is unsupported.

## Supported scope

- Ordinary files and directories on Unraid array mounts such as `/mnt/disk1`.
- Local ZFS datasets whose backing devices can be verified as non-USB and
  non-removable. Each dataset gets its own `.RecycleBin` and SQLite database.
- Atomic, same-filesystem rename only.

The following are intentionally rejected:

- `/mnt/user` and `/mnt/user0` virtual user-share paths.
- `/mnt/cache*` cache paths. Independently mounted local ZFS pools remain
  eligible when their backing devices pass the normal safety checks.
- `/mnt/disks` (Unassigned Devices), `/mnt/remotes`, remote filesystems and
  arbitrary external mounts.
- `/boot`, including the Unraid boot USB device.
- USB-backed or removable storage, symbolic links, nested mount points and
  any storage topology that cannot be verified safely.
- Cross-filesystem copy-and-delete operations.

Linux cannot always identify the physical enclosure of a disk. For example,
an eSATA enclosure may present exactly like an internal SATA disk. The plugin
therefore uses mount ownership, transport, removable flags and sysfs topology;
unknown layouts are blocked, but physical placement cannot be guaranteed from
software alone.

## Requirements

| Component | Requirement |
|---|---|
| Unraid OS | 7.3.2 or newer |
| Dynamix File Manager | Installed |
| PHP | Unraid-bundled PHP 8.x with PDO SQLite |
| ZFS support | Unraid `zfs`, `zpool` and `lsblk` tools |

## Install

### Unraid Community Applications

After the plugin is accepted into Community Applications, open **Apps** and
search for `Dynamix File Recycle Bin`.

### Unraid Plugin Manager

Open **Plugins -> Install Plugin** and paste:

```text
https://raw.githubusercontent.com/xO-ox-ai/dynamix.file.recycle/main/dynamix.file.recycle.plg
```

The Plugin Manager downloads the versioned package, verifies its SHA-256
digest and runs the install hook. Open **Settings -> User Programs -> Dynamix
File Recycle Bin** to review the master switch and maintenance policy. Open
**Tools -> Disk Utilities -> Recycle Bin** to view, restore or permanently
delete tracked recycle items.

Command-line installation uses the same official Plugin Manager path:

```bash
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin install \
  https://raw.githubusercontent.com/xO-ox-ai/dynamix.file.recycle/main/dynamix.file.recycle.plg
```

## Basic usage

1. Open a physical disk or supported ZFS dataset in DFM.
2. Select one or more files or directories with DFM's checkboxes.
3. Click **Recycle** immediately to the right of the native **Delete** button.
4. The server checks every selected path, mount, filesystem and backing device.
5. Confirm only after every check succeeds.
6. Open **Tools -> Disk Utilities -> Recycle Bin** to restore or permanently
   purge tracked items.

The Settings page lists every volume that currently passes the backend safety
checks. All are selected on first install. Saving converts that initial policy
to an explicit allowlist: unchecked volumes remain visible in history, but new
recycle, restore, purge and automatic maintenance actions are blocked there.
Array disks and ZFS datasets are displayed as separate trees; ZFS entries keep
their native pool/dataset hierarchy.

Example layout:

| Original path | Recycled path | Database |
|---|---|---|
| `/mnt/disk1/Movies/x.mkv` | `/mnt/disk1/.RecycleBin/Movies/x.mkv.__recycle_UUID` | `/mnt/disk1/.RecycleBin/.dynamix-file-recycle.sqlite` |
| `/mnt/tank/photos/a.jpg` | `/mnt/tank/.RecycleBin/photos/a.jpg.__recycle_UUID` | `/mnt/tank/.RecycleBin/.dynamix-file-recycle.sqlite` |

For ZFS, `/mnt/tank` in the example must be the dataset's actual mount point;
pool aliases and parent filesystems are not substituted.

## Persistence and diagnostics

- Recycled data and history: `<volume>/.RecycleBin/` and its SQLite database.
- Persistent settings: `/boot/config/plugins/dynamix.file.recycle/`.
- Runtime log: `/usr/local/emhttp/state/plugins/dynamix.file.recycle/logs/dynamix.file.recycle.log`.
- Reboot-surviving error audit:
  `/boot/config/plugins/dynamix.file.recycle/logs/audit.log`.

The runtime log is stored in RAM and is lost on reboot. Errors are also copied
to the bounded persistent audit log, while operation records live in each
volume's SQLite database.

No independent hourly or daily maintenance task is installed. Entering a
scheduled-cleanup cron expression in Settings creates the plugin's only
automatic task through Unraid's `update_cron`; leaving it empty creates no cron
entry. At the selected time the enabled bins are emptied and database/history
maintenance runs in the same job.

## Uninstall

Open **Plugins**, select **Dynamix File Recycle Bin**, and click **Remove**.
From a terminal, the equivalent Plugin Manager command is:

```bash
/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin remove \
  dynamix.file.recycle.plg
```

Uninstall removes plugin code, scheduled tasks and volatile logs. It preserves
settings, the persistent audit log, and every `.RecycleBin` directory with its
SQLite database. Inspect those directories manually before deleting them if a
complete data wipe is required.

## Security

- Every API action requires an authenticated Unraid administrator session.
- Every request is POST-only and uses Unraid's native CSRF validation.
- A short-lived signed inspection token binds confirmation to the same path,
  inode and metadata that the server checked immediately before the move.
- Symlink aliases, path traversal, volume roots and nested mounts are rejected.
- Recycle, restore and purge transitions are recorded before and after the
  filesystem operation so interrupted operations can be recovered.

## Support

[GitHub Issues](https://github.com/xO-ox-ai/dynamix.file.recycle/issues)

## License

MIT, see [LICENSE](LICENSE).
