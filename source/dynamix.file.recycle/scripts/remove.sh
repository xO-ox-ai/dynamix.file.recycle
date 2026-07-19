#!/bin/bash
# Removal hook for Dynamix File Recycle Bin.
# Invoked by the Unraid plugin manager when the user clicks Remove.
#
# IMPORTANT: We deliberately PRESERVE per-volume .RecycleBin directories and
# the user's cfg under /boot, so uninstall does not cause data loss. Users
# who want a full wipe can delete them manually (instructions printed below).
set -uo pipefail

# 1. De-register cron + logrotate.
rm -f /etc/cron.hourly/dynamix.file.recycle
rm -f /etc/logrotate.d/dynamix.file.recycle

# 2. Remove plugin code.
PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
rm -rf "$PLUGIN_DIR"

# 3. Remove transient state (SQLite + logs).
rm -rf /usr/local/emhttp/state/plugins/dynamix.file.recycle

cat <<'EOF'
Dynamix File Recycle Bin has been removed.

NOTE: Per-volume .RecycleBin directories and your settings under
/boot/config/plugins/dynamix.file.recycle/ have been PRESERVED so you do not
lose data. To find and manually wipe them:

  find /mnt -maxdepth 3 -type d -name .RecycleBin -print
  rm -rf /boot/config/plugins/dynamix.file.recycle

说明：各卷的 .RecycleBin 目录与 /boot/config/plugins/dynamix.file.recycle/
下的配置已被保留，避免卸载导致数据丢失。如需完全清理，请手动执行：

  find /mnt -maxdepth 3 -type d -name .RecycleBin -print
  rm -rf /boot/config/plugins/dynamix.file.recycle
EOF
