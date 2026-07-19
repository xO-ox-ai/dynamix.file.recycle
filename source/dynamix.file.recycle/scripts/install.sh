#!/bin/bash
# Install hook for Dynamix File Recycle Bin.
# Invoked by the Unraid plugin manager AFTER the .txz has been extracted into
# the root filesystem. Idempotent — safe to re-run.
set -euo pipefail

PLUGIN_DIR="/usr/local/emhttp/plugins/dynamix.file.recycle"
CFG_DIR="/boot/config/plugins/dynamix.file.recycle"
STATE_DIR="/usr/local/emhttp/state/plugins/dynamix.file.recycle"

# 1. Persistent config dir (under /boot so it survives reboot).
mkdir -p "$CFG_DIR"
if [[ ! -f "$CFG_DIR/dynamix.file.recycle.cfg" ]]; then
  cp -f "$PLUGIN_DIR/dynamix.file.recycle.cfg.default" \
        "$CFG_DIR/dynamix.file.recycle.cfg" 2>/dev/null || true
fi

# 2. State dir (SQLite, logs).
mkdir -p "$STATE_DIR"

# 3. Initialise SQLite.
if [[ -x /usr/local/bin/php ]]; then
  /usr/local/bin/php "$PLUGIN_DIR/include/Bootstrap.php" init || true
fi

# 4. Register hourly cron.
if [[ -f "$PLUGIN_DIR/cron/dynamix.file.recycle.cron" ]]; then
  cp -f "$PLUGIN_DIR/cron/dynamix.file.recycle.cron" \
        /etc/cron.hourly/dynamix.file.recycle
  chmod 0755 /etc/cron.hourly/dynamix.file.recycle
fi

# 5. Register logrotate entry.
if [[ -f "$PLUGIN_DIR/cron/dynamix.file.recycle.logrotate" ]]; then
  cp -f "$PLUGIN_DIR/cron/dynamix.file.recycle.logrotate" \
        /etc/logrotate.d/dynamix.file.recycle
fi

# 6. Make the maintenance wrapper executable.
if [[ -f "$PLUGIN_DIR/scripts/recycle-maintain" ]]; then
  chmod 0755 "$PLUGIN_DIR/scripts/recycle-maintain"
fi

echo "Dynamix File Recycle Bin installed."
echo "  Open Tools -> Recycle Bin to browse the bin."
echo "  Open Settings -> Dynamix File Recycle Bin to enable the feature."
