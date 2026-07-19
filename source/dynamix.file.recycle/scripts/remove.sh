#!/bin/bash
# Removal hook for Dynamix File Recycle Bin.
# Invoked by the Unraid plugin manager when the user clicks Remove.
#
# IMPORTANT: We deliberately PRESERVE per-volume .RecycleBin directories and
# the user's cfg under /boot, so uninstall does not cause data loss. Users
# who want a full wipe can delete them manually (instructions printed below).
set -uo pipefail

# 1. De-register the generated schedule and any legacy unconditional job.
rm -f /boot/config/plugins/dynamix.file.recycle/dynamix.file.recycle.cron
rm -f /etc/cron.hourly/dynamix.file.recycle
rm -f /etc/logrotate.d/dynamix.file.recycle
if [[ -x /usr/local/sbin/update_cron ]]; then
  /usr/local/sbin/update_cron
fi

# 2. Remove plugin code.
PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
rm -rf "$PLUGIN_DIR"

# Remove only this plugin's global WebGUI translation and its compiled cache.
rm -f /usr/local/emhttp/languages/zh_CN/dynamix.file.recycle.txt
rm -f /usr/local/emhttp/languages/zh_CN/dynamix.file.recycle.dot

# 3. Remove volatile cache and locks only. Configuration, persistent audit and
#    per-volume SQLite databases remain recoverable after uninstall.
rm -rf /usr/local/emhttp/state/plugins/dynamix.file.recycle
rm -rf /run/dynamix.file.recycle

cat <<'EOF'
Dynamix File Recycle Bin has been removed.

NOTE: Per-volume .RecycleBin directories and SQLite databases, settings, and the
bounded audit log under /boot/config/plugins/dynamix.file.recycle/ are kept.
To find and manually wipe them:

  find /mnt -maxdepth 3 -type d -name .RecycleBin -print
  rm -rf /boot/config/plugins/dynamix.file.recycle

说明：各卷的 .RecycleBin 目录，以及 /boot/config/plugins/dynamix.file.recycle/
下的配置和审计日志均已保留；恢复记录保存在各卷 SQLite 数据库中。如需完全清理：

  find /mnt -maxdepth 3 -type d -name .RecycleBin -print
  rm -rf /boot/config/plugins/dynamix.file.recycle
EOF
