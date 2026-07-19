# Dynamix File Recycle Bin

> 🇨🇳 中文版请见 [README.zh.md](README.zh.md)

A safe **recycle bin for the Unraid Dynamix File Manager**. Instead of deleting
files permanently, this plugin adds a dedicated **"Move to Recycle Bin"**
button next to each row in the official file browser and moves the selected
files/folders into a per-volume `.RecycleBin` directory, where they can be
browsed, restored, or purged on a schedule.

- ✅ Injects a button into the **Dynamix File Manager** Browse page **without
  modifying any Unraid core file** (uses the official `Menu='Buttons'`
  injection channel).
- ✅ One recycle bin per volume. v1 supports `/mnt/disk*` data drives and
  individual ZFS datasets. (`/mnt/user`, `/mnt/cache*` are intentionally out
  of scope for v1.)
- ✅ Configurable maintenance: age-based eviction, capacity threshold eviction
  (LRU), optional scheduled auto-empty, log level and log retention.
- ✅ Bilingual UI and docs (English + 中文), following the Unraid system
  language.
- ✅ Compliant with Unraid 7.x CSRF requirements (relies on Unraid's
  `auto_prepend` verification; the plugin never re-implements CSRF).

## Requirements

| Component | Version |
|---|---|
| Unraid OS | 7.3.2 or newer |
| PHP | 8.x (bundled with Unraid) |
| Dynamix File Manager | present (ships with Unraid 7.3+) |
| Optional | `zfs` tools, when ZFS datasets are used |

## Installation

1. In Unraid, open **Plugins → Install Plugin**.
2. Paste the raw URL of `dynamix.file.recycle.plg` (see Releases).
3. Click **Install**. After installation the **Tools → Recycle Bin** page
   becomes available.
4. Open **Settings → Dynamix File Recycle Bin** to enable the feature and tune
   the maintenance policy.

## Usage

- Open **Main → Browse** (the Dynamix File Manager). Each row now has an extra
  icon button that moves the item to the recycle bin for that volume.
- Open **Tools → Recycle Bin** to browse, restore, or permanently purge items.
- Open **Settings → Dynamix File Recycle Bin** to switch the feature on/off,
  configure maintenance, log level, history recording, and language.

## Where does the recycle bin live?

| Source | Recycle bin location |
|---|---|
| `/mnt/disk1/Movies/x.mkv` | `/mnt/disk1/.RecycleBin/Movies/x.mkv` |
| `/mnt/tank/photos/2025/a.jpg` (ZFS) | `/mnt/tank/.RecycleBin/photos/2025/a.jpg` |

Original owner/group/mode are preserved so the file can be restored exactly.

## Security

- All write operations require an authenticated **admin** session.
- All paths are normalised and confined to their original volume; `..`
  traversal is rejected.
- Cross-filesystem moves fall back to `cp -a` + `rm` to guarantee correctness
  (the original is removed only after the copy succeeds).

## Documentation

- [中文说明](README.zh.md)
- [Design document](docs/DESIGN.md)
- [Changelog](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE).
