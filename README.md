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

Pick **one** of the methods below. The plugin URL is the same in both cases:

```
https://github.com/xO-ox-ai/dynamix.file.recycle/releases/download/v2026.07.19a/dynamix.file.recycle.plg
```

> Always copy the URL for the **specific release** you want from the
> [Releases page](https://github.com/xO-ox-ai/dynamix.file.recycle/releases).
> The URL above points to the latest published release.

### Method A — via the Unraid web UI (recommended)

1. Open **Plugins → Install Plugin**.
2. Paste the `.plg` URL from the Releases page into the input box.
3. Click **Install**. The plugin manager downloads the `.txz` package,
   verifies the MD5, extracts it, and runs the install hook (registers the
   cron job, initialises SQLite, copies the default config).
4. After install completes, **Tools → Recycle Bin** becomes available and a
   new entry appears in **Plugins** for future updates.
5. Open **Settings → Dynamix File Recycle Bin** to enable the feature and tune
   the maintenance policy.

### Method B — via the Unraid command line

Useful for headless servers or scripted installs.

```bash
# 1. Download the .plg descriptor onto the server.
wget -O /tmp/dynamix.file.recycle.plg \
  https://github.com/xO-ox-ai/dynamix.file.recycle/releases/download/v2026.07.19a/dynamix.file.recycle.plg

# 2. Feed it to the plugin manager. This performs the same install flow
#    as the web UI (download .txz -> verify MD5 -> extract -> hook).
/usr/local/emhttp/plugins/dynamix/scripts/plugin install /tmp/dynamix.file.recycle.plg
```

> If your Unraid build does not expose `plugin install` at that path, use the
> web-UI method — the resulting installation is identical.

## Uninstallation

The plugin preserves your data by default: per-volume `.RecycleBin/`
directories and your settings under
`/boot/config/plugins/dynamix.file.recycle/` are **kept** so you can re-install
later without losing anything.

### Method A — via the Unraid web UI (recommended)

1. Open **Plugins**, find **Dynamix File Recycle Bin** in the list.
2. Click the **gear icon → Remove Plugin** (or **Uninstall**).
3. Confirm. The removal hook de-registers the cron job and deletes the plugin
   code + transient state (SQLite/logs).
4. *(Optional)* To completely wipe the data and settings, run the manual
   cleanup commands below.

### Method B — via the Unraid command line

```bash
# 1. Run the plugin's own removal hook (the same path the plugin manager uses).
PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
if [ -x "$PLUGIN_DIR/scripts/remove.sh" ]; then
    "$PLUGIN_DIR/scripts/remove.sh"
else
    echo "Plugin directory not found — nothing to remove via hook."
fi

# 2. (Optional) wipe preserved data: all per-volume .RecycleBin/ directories.
#    Inspect first, then delete once you are sure:
find /mnt -maxdepth 4 -type d -name .RecycleBin -print
#    rm -rf $(find /mnt -maxdepth 4 -type d -name .RecycleBin)

# 3. (Optional) wipe preserved settings under /boot (survives reboot).
rm -rf /boot/config/plugins/dynamix.file.recycle
```

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
